<?php

namespace App\Models;

use Carbon\CarbonPeriod;
use Carbon\Exceptions\InvalidFormatException;
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

    private const START_TIME = '09:00';

    private const END_TIME = '18:00';

    private const REGULAR_WORK_MINUTES = 480;

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
     * この勤怠への修正申請を返す。
     *
     * @return HasMany この勤怠への修正申請の一覧
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
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
            ->sum(fn (AttendanceBreak $break) => $this->toMinutes($break->break_out) - $this->toMinutes($break->break_in));

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
     *
     * @param  User  $user  出勤するユーザー
     * @return self 今日の勤怠
     */
    public static function clockIn(User $user): self
    {
        $record = self::createOrFirst(
            ['user_id' => $user->id, 'date' => today()->toDateString()],
            ['clock_in' => now()->toTimeString()],
        );

        if ($record->wasRecentlyCreated) {
            $record->recalculate();
        }

        return $record;
    }

    /**
     * 今の時刻で退勤を打刻する。
     *
     * @return void 返す値はない（退勤の時刻と計算した値を、この勤怠に保存する）
     */
    public function clockOut(): void
    {
        $this->clock_out = now()->toTimeString();
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
     * 今の時刻で休憩戻を打刻し、戻りのない休憩のうち最新の1行に戻りの時刻を書く。
     *
     * @return void 返す値はない（休憩の戻りと計算した値を保存する）
     */
    public function breakOut(): void
    {
        $this->breaks()->whereNull('break_out')->latest('break_in')->first()->update(['break_out' => now()->toTimeString()]);
        $this->recalculate();
    }

    /**
     * 管理者の直接修正のフォームの値を、rewrite() が受け取る出勤、退勤、備考と休憩の行に組み直して渡す。
     * 入りと戻りが両方空の休憩の行は、追加用の空の行か消した休憩の行なので、休憩にしない。
     *
     * @param  array{new_clock_in: string, new_clock_out: string, new_break_in?: array<int, string|null>, new_break_out?: array<int, string|null>, comment: string}  $validated  バリデーションを通った入力
     * @return void 返す値はない（rewrite() が、書き換えた値と計算した値をこの勤怠に保存する）
     */
    public function applyAdminEdit(array $validated): void
    {
        $breakOuts = $validated['new_break_out'] ?? [];

        $breaks = collect($validated['new_break_in'] ?? [])
            ->map(fn (?string $breakIn, int $index): array => ['break_in' => $breakIn, 'break_out' => $breakOuts[$index] ?? null])
            ->reject(fn (array $break): bool => blank($break['break_in']) && blank($break['break_out']))
            ->values()
            ->all();

        $this->rewrite($validated['new_clock_in'], $validated['new_clock_out'], $validated['comment'], $breaks);
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

        foreach ($breaks as $break) {
            $this->breaks()->create([
                'break_in' => $this->keepSeconds($break['break_in'], $oldBreakIns),
                'break_out' => $this->keepSeconds($break['break_out'], $oldBreakOuts),
            ]);
        }

        $this->recalculate();
    }

    /**
     * 渡されたユーザーと月について、月の全部の日の勤怠一覧の行を返す。勤怠のない日は、日付のほかを null にする。
     *
     * @param  User  $user  勤怠を読むユーザー
     * @param  Carbon  $month  表示する月の中の日
     * @return array<int, array<string, int|string|null>> 日ごとの行（date、clock_in、clock_out、total_break_time、total_time、id）
     */
    public static function monthlyRows(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth();
        $end = $month->copy()->endOfMonth();

        $records = self::where('user_id', $user->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy('date');

        return collect(CarbonPeriod::create($start, $end))
            ->map(function (Carbon $day) use ($records): array {
                $record = $records->get($day->toDateString());

                return [
                    'date' => $day->isoFormat('MM/DD(ddd)'),
                    'clock_in' => $record?->formattedClockIn(),
                    'clock_out' => $record?->formattedClockOut(),
                    'total_break_time' => $record?->total_break_time,
                    'total_time' => $record?->total_time,
                    'id' => $record?->id,
                ];
            })
            ->all();
    }

    /**
     * ?date= の値から、表示する月の初めの日と、前の月と次の月の値を返す。値がないか読めないときは、今の月にする。
     *
     * @param  mixed  $value  ?date= の値
     * @return array{date: Carbon, previousMonth: string, nextMonth: string} 表示する月の初めの日と、前月と翌月の Y-m
     */
    public static function resolveMonthView(mixed $value): array
    {
        $date = today()->startOfMonth();

        if (is_string($value)) {
            try {
                $date = Carbon::parse($value)->startOfMonth();
            } catch (InvalidFormatException) {
                // 読めない値はエラーを出す画面がないので、今の月のままにする
            }
        }

        return [
            'date' => $date,
            'previousMonth' => $date->copy()->subMonth()->format('Y-m'),
            'nextMonth' => $date->copy()->addMonth()->format('Y-m'),
        ];
    }

    /**
     * この勤怠への承認待ちの修正申請を返す。なければ null を返す。
     *
     * @return Application|null 承認待ちの修正申請
     */
    public function pendingApplication(): ?Application
    {
        return $this->applications()->where('is_approved', false)->first();
    }

    /**
     * 勤怠詳細の画面に渡す値を返す。休憩は入りの早い順に、0からの連番で並べる。
     * エラーで戻ったときに送った値を渡すと、備考の書き忘れだけで時刻を打ち直さないよう、時刻、休憩、備考を送った値に置き換える。
     *
     * @param  array<string, mixed>  $oldInput  エラーで戻ったときに送った値（なければ空の配列で、勤怠の値のまま返す）
     * @return array<string, mixed> id、year、date、clock_in、clock_out、breaks、comment
     */
    public function toDetailData(array $oldInput = []): array
    {
        $date = Carbon::parse($this->date);

        $detail = [
            'id' => $this->id,
            'year' => $date->format('Y年'),
            'date' => $date->format('n月j日'),
            'clock_in' => $this->formattedClockIn(),
            'clock_out' => $this->formattedClockOut(),
            'breaks' => $this->breaks()
                ->orderBy('break_in')
                ->get()
                ->map(fn (AttendanceBreak $break): array => [
                    'break_in' => $break->formattedBreakIn(),
                    'break_out' => $break->formattedBreakOut(),
                ])
                ->all(),
            'comment' => $this->comment,
        ];

        if ($oldInput === []) {
            return $detail;
        }

        $breakIns = $oldInput['new_break_in'] ?? [];
        $breakOuts = $oldInput['new_break_out'] ?? [];
        $breaks = [];

        // 送った行の番号を保ち、エラーの文言が同じ行に出るようにする
        foreach (is_array($breakIns) ? $breakIns : [] as $index => $breakIn) {
            $breaks[$index] = [
                'break_in' => $breakIn,
                'break_out' => is_array($breakOuts) ? ($breakOuts[$index] ?? null) : null,
            ];
        }

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
     * 出勤を H:i の文字で返す。
     *
     * @return string 出勤（例：09:00）
     */
    public function formattedClockIn(): string
    {
        return substr($this->clock_in, 0, 5);
    }

    /**
     * 退勤を H:i の文字で返す。退勤がなければ null を返す。
     *
     * @return string|null 退勤（例：18:00）
     */
    public function formattedClockOut(): ?string
    {
        return $this->clock_out === null ? null : substr($this->clock_out, 0, 5);
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

    private function toHourMinuteText(int $minutes): string
    {
        return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
    }

    private function keepSeconds(?string $sentTime, array $currentTimes): ?string
    {
        // 画面は分までしか入力できないので、時と分を変えていない時刻は、打刻で入った秒を消さないように今の値を使う
        return collect($currentTimes)->first(fn (?string $currentTime): bool => $currentTime !== null && substr($currentTime, 0, 5) === $sentTime) ?? $sentTime;
    }

    private function toMinutes(string $time): int
    {
        // 画面に出る時刻と計算の結果を合わせるため、秒は切り捨てる
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
