<?php

namespace App\Models;

use App\Support\TimeFormat;
use Carbon\CarbonPeriod;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AttendanceRecord extends Model
{
    use HasFactory;

    // 提供された Blade の打刻のボタンがこの値を送るので、値の文字は変えない
    public const ACTION_CLOCK_IN = 'clock_in';

    public const ACTION_CLOCK_OUT = 'clock_out';

    public const ACTION_BREAK_IN = 'break_in';

    public const ACTION_BREAK_OUT = 'break_out';

    public const ACTIONS = [self::ACTION_CLOCK_IN, self::ACTION_CLOCK_OUT, self::ACTION_BREAK_IN, self::ACTION_BREAK_OUT];

    // 要件で決まった始業と終業の時刻で、出勤がこれより後なら遅刻、退勤がこれより前なら早退にする
    private const START_TIME = '09:00';

    private const END_TIME = '18:00';

    // 要件で決まった基準で、勤務がこの分を超えた分を残業にする
    private const REGULAR_WORK_MINUTES = 480;

    // 要件で決まった基準で、勤務がこの分を超えた日を長時間労働にする
    private const LONG_WORK_MINUTES = 600;

    protected $fillable = [
        'user_id',
        'date',
        'clock_in',
        'clock_out',
        'comment',
    ];

    protected $casts = [
        'is_late' => 'boolean',
        'is_early_leave' => 'boolean',
        'is_long_work' => 'boolean',
    ];

    /**
     * モデルのイベントを登録する。Laravel が、このモデルを最初に使うときに1回だけ呼ぶ。
     * 勤怠を消すときに、同じ人と日付の申請も消す。申請は勤怠の番号を持たず、データベースの外部キーでは勤怠と一緒に消えないため。申請の休憩は、申請への外部キーで一緒に消える。
     *
     * @return void 返す値はない
     */
    protected static function booted(): void
    {
        static::deleting(function (AttendanceRecord $attendanceRecord): void {
            $attendanceRecord->sameDayApplications()->delete();
        });
    }

    /**
     * この勤怠のユーザーを返す。
     *
     * @return BelongsTo この勤怠のユーザー
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * この勤怠の休憩を返す。
     *
     * @return HasMany この勤怠の休憩の一覧
     */
    public function breaks(): HasMany
    {
        return $this->hasMany(AttendanceBreak::class);
    }

    /**
     * この勤怠と同じ人と日付の修正申請を読むクエリを返す。
     * 申請は勤怠がない日にも出せるように勤怠の番号を持たず、人と日付で勤怠とつながるので、リレーションではなく人と日付で探す。
     *
     * @return Builder<Application> 同じ人と日付の修正申請を読むクエリ
     */
    public function sameDayApplications(): Builder
    {
        return Application::query()
            ->where('user_id', $this->user_id)
            ->where('date', $this->date);
    }

    /**
     * 休憩時間、勤務時間、残業時間と、遅刻、早退、長時間労働のフラグを計算して保存する。
     * 時刻を書いた後に呼ぶ。退勤がないときは、勤務時間と残業時間を空にする。
     *
     * @return void 返す値はない（計算した値は、この勤怠に保存する）
     */
    public function recalculate(): void
    {
        // 前に読み込んだ休憩を使い回すと、後から足したり消したりした休憩が計算に入らないので、毎回データベースから読み直す
        $breakMinutes = $this->breaks()
            ->whereNotNull('break_out')
            ->get()
            ->sum(fn (AttendanceBreak $break): int => $this->toMinutes($break->break_out) - $this->toMinutes($break->break_in));

        $clockIn = $this->toMinutes($this->clock_in);
        $this->break_minutes = $breakMinutes;
        $this->is_late = $clockIn > $this->toMinutes(self::START_TIME);

        if ($this->clock_out === null) {
            $this->work_minutes = null;
            $this->overtime_minutes = null;
            $this->is_early_leave = false;
            $this->is_long_work = false;
            $this->save();

            return;
        }

        $clockOut = $this->toMinutes($this->clock_out);
        $workMinutes = max($clockOut - $clockIn - $breakMinutes, 0);

        $this->work_minutes = $workMinutes;
        $this->overtime_minutes = max($workMinutes - self::REGULAR_WORK_MINUTES, 0);
        $this->is_early_leave = $clockOut < $this->toMinutes(self::END_TIME);
        $this->is_long_work = $workMinutes > self::LONG_WORK_MINUTES;
        $this->save();
    }

    /**
     * 今の時刻で出勤を打刻し、今日の勤怠を返す。同じ日の勤怠がもうあれば、その行を返して書き換えない。
     * 日付と時刻は、1回だけ取った今の日時から作る。こうすると、0時をまたいでも、日付と時刻が食い違わない。
     *
     * @param  User  $user  出勤するユーザー
     * @return self 今日の勤怠
     */
    public static function clockIn(User $user): self
    {
        $now = now();
        $attendanceRecord = self::createOrFirst(
            ['user_id' => $user->id, 'date' => $now->toDateString()],
            ['clock_in' => $now->toTimeString()],
        );

        if ($attendanceRecord->wasRecentlyCreated) {
            $attendanceRecord->recalculate();
        }

        return $attendanceRecord;
    }

    /**
     * 今の時刻で退勤を打刻する。今の時刻が出勤と全く同じ秒なら、何も書かない。
     *
     * @return void 返す値はない（退勤の時刻と計算した値を、この勤怠に保存する）
     */
    public function clockOut(): void
    {
        $now = now()->toTimeString();

        // 出勤と全く同じ秒の退勤は長さ0秒の勤務になり、実際の勤務ではないので書かない
        if ($now === $this->clock_in) {
            return;
        }

        $this->clock_out = $now;
        $this->recalculate();
    }

    /**
     * 今の時刻で休憩入を打刻し、休憩の行を作る。
     *
     * @return void 返す値はない（休憩の行と計算した値を保存する）
     */
    public function breakIn(): void
    {
        $this->breaks()->create(['break_in' => now()->toTimeString()]);
        $this->recalculate();
    }

    /**
     * 今の時刻で休憩戻を打刻し、戻りのない休憩のうち最新の1行に戻りの時刻を書く。今の時刻がその休憩の入りと全く同じ秒なら、何も書かない。
     *
     * @return void 返す値はない（休憩の戻りと計算した値を保存する）
     */
    public function breakOut(): void
    {
        $openBreak = $this->breaks()->open()->latest('break_in')->first();
        $now = now()->toTimeString();

        // 休憩入と全く同じ秒の休憩戻は長さ0秒の休憩になり、実際の休憩ではないので書かない
        if ($now === $openBreak->break_in) {
            return;
        }

        $openBreak->update(['break_out' => $now]);
        $this->recalculate();
    }

    /**
     * 休憩の入りの配列と戻りの配列から、同じ番号どうしを組にした休憩の行を返す。
     * 入りと戻りが両方空の行は、追加用の空の行か消した休憩の行なので、休憩にしない。修正申請と管理者の直接修正で、休憩にする行の決まりをそろえるため、この1か所にまとめる。
     *
     * @param  array<int|string, string|null>  $breakIns  休憩の入りの配列（フォームの new_break_in）
     * @param  array<int|string, string|null>  $breakOuts  休憩の戻りの配列（フォームの new_break_out）
     * @return array<int, array{break_in: string|null, break_out: string|null}> 休憩の行（0からの連番）
     */
    public static function toBreakRows(array $breakIns, array $breakOuts): array
    {
        return collect($breakIns)
            ->map(fn (?string $breakIn, int|string $index): array => ['break_in' => $breakIn, 'break_out' => $breakOuts[$index] ?? null])
            ->reject(fn (array $break): bool => blank($break['break_in']) && blank($break['break_out']))
            ->values()
            ->all();
    }

    /**
     * 管理者の直接修正のフォームの値を、rewrite() が受け取る出勤、退勤、備考と休憩の行に組み直して渡す。
     * 入りと戻りが両方空の休憩の行は、toBreakRows() で除く。
     *
     * @param  array{new_clock_in: string, new_clock_out: string, new_break_in?: array<int, string|null>, new_break_out?: array<int, string|null>, comment: string}  $validated  バリデーションを通った入力
     * @return void 返す値はない（rewrite() が、書き換えた値と計算した値をこの勤怠に保存する）
     */
    public function applyAdminEdit(array $validated): void
    {
        $breaks = self::toBreakRows($validated['new_break_in'] ?? [], $validated['new_break_out'] ?? []);

        $this->rewrite($validated['new_clock_in'], $validated['new_clock_out'], $validated['comment'], $breaks);
    }

    /**
     * 管理者の直接修正で、渡された人と日付の勤怠を作って書き、作った勤怠を返す。勤怠がない日の直接修正から呼ぶ。
     * 勤怠は出勤が必須なので、送られた出勤で作ってから applyAdminEdit() で書く。勤怠の作成と書き込みを1つのトランザクションにまとめ、どちらかが失敗したら両方を元に戻す。
     *
     * @param  User  $user  勤怠を作る一般ユーザー
     * @param  string  $date  勤怠を作る日（例：2026-11-03）
     * @param  array{new_clock_in: string, new_clock_out: string, new_break_in?: array<int, string|null>, new_break_out?: array<int, string|null>, comment: string}  $validated  バリデーションを通った入力
     * @return self 作って書いた勤怠
     */
    public static function createFromAdminEdit(User $user, string $date, array $validated): self
    {
        return DB::transaction(function () use ($user, $date, $validated): self {
            $attendanceRecord = self::firstOrCreate(
                ['user_id' => $user->id, 'date' => $date],
                ['clock_in' => $validated['new_clock_in']],
            );
            $attendanceRecord->applyAdminEdit($validated);

            return $attendanceRecord;
        });
    }

    /**
     * 渡された出勤、退勤、備考と休憩の行で、この勤怠を1つのトランザクションで書き換える。管理者の直接修正と修正申請の承認の両方から呼ぶ。
     * 時と分を変えていない出勤と退勤は、今の値（秒を含む）を残す。休憩の秒は replaceBreaks() が残す。
     *
     * @param  string  $clockIn  書き換える出勤（例：09:00）
     * @param  string  $clockOut  書き換える退勤（例：18:00）
     * @param  string  $comment  書き換える備考
     * @param  array<int, array{break_in: string, break_out: string|null}>  $breaks  作る休憩の行
     * @return void 返す値はない（書き換えた値と計算した値を、この勤怠に保存する）
     */
    public function rewrite(string $clockIn, string $clockOut, string $comment, array $breaks): void
    {
        DB::transaction(function () use ($clockIn, $clockOut, $comment, $breaks): void {
            $this->clock_in = $this->keepSeconds($clockIn, [$this->clock_in]);
            $this->clock_out = $this->keepSeconds($clockOut, [$this->clock_out]);
            $this->comment = $comment;

            $this->replaceBreaks($breaks);
        });
    }

    /**
     * 休憩の行を全部消して渡された行に置換し、計算し直す。rewrite() から呼ぶ。
     * 時と分が消す前の休憩の時刻と同じ時刻は、消す前の値（秒を含む）で作る。入りは消す前の入りと、戻りは消す前の戻りと比べる。
     *
     * @param  array<int, array{break_in: string, break_out: string|null}>  $breaks  作る休憩の行
     * @return void 返す値はない（休憩の行と計算した値を保存する）
     */
    public function replaceBreaks(array $breaks): void
    {
        $oldBreaks = $this->breaks()->get();
        $oldBreakIns = $oldBreaks->pluck('break_in')->all();
        $oldBreakOuts = $oldBreaks->pluck('break_out')->all();

        $this->breaks()->delete();

        $this->breaks()->createMany(
            collect($breaks)
                ->map(fn (array $break): array => [
                    'break_in' => $this->keepSeconds($break['break_in'], $oldBreakIns),
                    'break_out' => $this->keepSeconds($break['break_out'], $oldBreakOuts),
                ])
                ->all(),
        );

        $this->recalculate();
    }

    /**
     * 渡されたユーザーと月について、月の全部の日の勤怠一覧の行を返す。勤怠のない日は、日付のほかを null にする。
     *
     * @param  User  $user  勤怠を読むユーザー
     * @param  Carbon  $month  表示する月の中の日
     * @return array<int, array<string, int|string|null>> 日ごとの行（date、full_date、clock_in、clock_out、total_break_time、total_time、id）
     */
    public static function monthlyRows(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $attendanceRecords = self::where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy('date');

        return collect(CarbonPeriod::create($start, $end))
            ->map(function (Carbon $day) use ($attendanceRecords): array {
                $attendanceRecord = $attendanceRecords->get($day->toDateString());

                return [
                    'date' => $day->isoFormat('MM/DD(ddd)'),
                    'full_date' => $day->toDateString(),
                    'clock_in' => $attendanceRecord?->formattedClockIn(),
                    'clock_out' => $attendanceRecord?->formattedClockOut(),
                    'total_break_time' => $attendanceRecord?->total_break_time,
                    'total_time' => $attendanceRecord?->total_time,
                    'id' => $attendanceRecord?->id,
                ];
            })
            ->all();
    }

    /**
     * 渡されたユーザーと月について、CSV に書く日ごとの行を返す。行の値は、ID、名前、日付、出勤、退勤、休憩、合計の順にする。
     * 給与計算など他のシステムとつなぐときに人を見分けられるように、全部の行の先頭にユーザーの ID と名前を入れる。
     * 休憩と合計は、画面と同じく `8:00` の形にする。勤怠のない日は、ID と名前と日付のほかを null のまま返す。
     * 名前が `=`、`+`、`-`、`@` で始まるときは、表計算ソフトが数式として動かさないように、先頭にタブを付ける。
     *
     * @param  User  $user  勤怠を書き出すユーザー
     * @param  Carbon  $month  書き出す月の中の日
     * @return array<int, array<int, int|string|null>> 日ごとの行（ID、名前、日付、出勤、退勤、休憩、合計）
     */
    public static function csvRows(User $user, Carbon $month): array
    {
        return collect(self::monthlyRows($user, $month))
            ->map(fn (array $row): array => [
                $user->id,
                self::escapeFormula($user->name),
                $row['date'],
                $row['clock_in'],
                $row['clock_out'],
                $row['total_break_time'] ? Carbon::parse($row['total_break_time'])->format('G:i') : null,
                $row['total_time'] ? Carbon::parse($row['total_time'])->format('G:i') : null,
            ])
            ->all();
    }

    /**
     * ?date= の値を、その日の初めの時刻にして返す。値がないか読めないときは、今日にする。
     *
     * @param  mixed  $value  ?date= の値
     * @return Carbon 表示する日の初めの時刻
     */
    public static function parseDateQuery(mixed $value): Carbon
    {
        if (is_string($value)) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (InvalidFormatException) {
                // 読めない値はエラーを出す画面がないので、今日にする
            }
        }

        return today();
    }

    /**
     * ルートの {date} の文字を、Y-m-d の形で厳しく読み、その日の初めの時刻にして返す。読めない文字と日付としてない値は null を返す。
     * 今日との前後は比べない（今日と今日より後の日を受け付けないのは、呼ぶ側で決める）。
     *
     * @param  string  $value  ルートの {date} の文字（例：2026-11-03）
     * @return Carbon|null その日の初めの時刻。読めなければ null
     */
    public static function parseDateParameter(string $value): ?Carbon
    {
        try {
            // 先頭の ! で、日付だけを読み、時刻を 00:00:00 にする
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (InvalidFormatException) {
            return null;
        }

        // 2026-02-30 は 2026-03-02 に、2026-2-3 は 2026-02-03 に読み替えられるので、読んだ日を同じ形に戻して元の文字と同じときだけ受け付ける
        return $date->format('Y-m-d') === $value ? $date : null;
    }

    /**
     * ?date= の値から、表示する月の初めの日と、前の月と次の月の値を返す。値がないか読めないときは、今の月にする。
     *
     * @param  mixed  $value  ?date= の値
     * @return array{date: Carbon, previousMonth: string, nextMonth: string} 表示する月の初めの日と、前月と翌月の Y-m
     */
    public static function resolveMonthView(mixed $value): array
    {
        $date = self::parseDateQuery($value)->startOfMonth();

        return [
            'date' => $date,
            'previousMonth' => $date->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $date->copy()->addMonth()->format('Y-m'),
        ];
    }

    /**
     * この勤怠と同じ人と日付の、承認待ちの修正申請を返す。なければ null を返す。
     *
     * @return Application|null 承認待ちの修正申請
     */
    public function pendingApplication(): ?Application
    {
        return $this->sameDayApplications()->where('is_approved', false)->first();
    }

    /**
     * 勤怠詳細の画面に渡す値を返す。休憩は入りの早い順に、0からの連番で並べる。
     * エラーで戻ったときに送った値を渡すと、備考の書き忘れだけで時刻を打ち直さないよう、時刻、休憩、備考を送った値に置き換える。
     * 勤怠がない日の保存していない勤怠でも呼べ、そのときは id と時刻を null、休憩を空の配列にして返す。
     *
     * @param  array<string, mixed>  $oldInput  エラーで戻ったときに送った値（なければ空の配列で、勤怠の値のまま返す）
     * @return array<string, mixed> id、year、date、full_date（フォームの送り先に使う Y-m-d の日付）、clock_in、clock_out、breaks、comment
     */
    public function toDetailData(array $oldInput = []): array
    {
        $detailDate = TimeFormat::toDetailDate($this->date);

        $detail = [
            'id' => $this->id,
            'year' => $detailDate['year'],
            'date' => $detailDate['date'],
            'full_date' => $this->date,
            'clock_in' => $this->formattedClockIn(),
            'clock_out' => $this->formattedClockOut(),
            'breaks' => $this->breaks()
                ->orderBy('break_in')
                ->get()
                ->map(fn (AttendanceBreak $break): array => $break->toBreakRow())
                ->all(),
            'comment' => $this->comment,
        ];

        if ($oldInput === []) {
            return $detail;
        }

        $breakIns = $oldInput['new_break_in'] ?? [];
        $breakOuts = $oldInput['new_break_out'] ?? [];

        // map() はキーを保つので、送った行の番号のまま並び、エラーの文言が同じ行に出る
        $breaks = collect(is_array($breakIns) ? $breakIns : [])
            ->map(fn (mixed $breakIn, int|string $index): array => [
                'break_in' => $breakIn,
                'break_out' => is_array($breakOuts) ? ($breakOuts[$index] ?? null) : null,
            ])
            ->all();

        // 画面が最後に追加用の空の行を足すので、送った最後の行が両方空なら除き、空の行が2つ並ばないようにする
        $lastIndex = array_key_last($breaks);

        if ($lastIndex !== null && blank($breaks[$lastIndex]['break_in']) && blank($breaks[$lastIndex]['break_out'])) {
            unset($breaks[$lastIndex]);
        }

        return array_merge($detail, [
            'clock_in' => $oldInput['new_clock_in'] ?? null,
            'clock_out' => $oldInput['new_clock_out'] ?? null,
            'breaks' => $breaks,
            'comment' => $oldInput['comment'] ?? null,
        ]);
    }

    /**
     * 出勤を H:i の文字で返す。勤怠がない日の保存していない勤怠で、出勤がなければ null を返す。
     *
     * @return string|null 出勤（例：09:00）
     */
    public function formattedClockIn(): ?string
    {
        return TimeFormat::toHourMinute($this->clock_in);
    }

    /**
     * 退勤を H:i の文字で返す。退勤がなければ null を返す。
     *
     * @return string|null 退勤（例：18:00）
     */
    public function formattedClockOut(): ?string
    {
        return TimeFormat::toHourMinute($this->clock_out);
    }

    /**
     * 勤務時間を 08:00 の形で返すアクセサ。total_time の名前で読む。勤務時間が空なら null を返す。
     *
     * @return Attribute<string|null, never> 勤務時間の文字
     */
    protected function totalTime(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->work_minutes === null ? null : $this->toHourMinuteText($this->work_minutes));
    }

    /**
     * 休憩時間を 01:00 の形で返すアクセサ。total_break_time の名前で読む。休憩時間が空か0なら null を返す。
     *
     * @return Attribute<string|null, never> 休憩時間の文字
     */
    protected function totalBreakTime(): Attribute
    {
        return Attribute::get(fn (): ?string => $this->break_minutes ? $this->toHourMinuteText($this->break_minutes) : null);
    }

    /**
     * 分の数を、08:00 の形の文字にして返す。
     *
     * @param  int  $minutes  分の数（例：480）
     * @return string 時と分の文字（例：08:00）
     */
    private function toHourMinuteText(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    /**
     * 送った時刻と時と分が同じ今の時刻があれば、その今の時刻（秒を含む）を返し、なければ送った時刻を返す。
     * 画面は分までしか入力できないので、時と分を変えていない時刻は、打刻で入った秒を消さないように今の値を使う。
     *
     * @param  string|null  $sentTime  画面から送った時刻（例：09:00）。休憩の戻りが空なら null
     * @param  array<int, string|null>  $currentTimes  今の勤怠か休憩の時刻の一覧（例：09:00:19）
     * @return string|null 保存する時刻
     */
    private function keepSeconds(?string $sentTime, array $currentTimes): ?string
    {
        return collect($currentTimes)->first(fn (?string $currentTime): bool => $currentTime !== null && TimeFormat::toHourMinute($currentTime) === $sentTime) ?? $sentTime;
    }

    /**
     * 時刻の文字を、0時からの分の数にして返す。画面に出る時刻と計算の結果を合わせるため、秒は切り捨てる。
     *
     * @param  string  $time  時刻（例：09:00:19）
     * @return int 0時からの分の数（例：540）
     */
    private function toMinutes(string $time): int
    {
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }

    /**
     * 渡された文字が `=`、`+`、`-`、`@` で始まるときは、先頭にタブを付けて返す。それ以外は、そのまま返す。
     * 先頭にタブがあると、表計算ソフトは値を数式ではなく文字として扱う。
     * タブと改行で始まる値は数えない。名前の前後の空白（タブと改行も入る）は、Laravel の初期のミドルウェアの `TrimStrings` が消すので、タブと改行で始まる名前は入らない。
     *
     * @param  string  $value  CSV に書く文字
     * @return string 数式にならないようにした文字
     */
    private static function escapeFormula(string $value): string
    {
        if (in_array(substr($value, 0, 1), ['=', '+', '-', '@'], true)) {
            return "\t".$value;
        }

        return $value;
    }
}
