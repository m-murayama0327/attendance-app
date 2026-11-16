<?php

namespace App\Models;

use App\Support\TimeFormat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'new_clock_in',
        'new_clock_out',
        'comment',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * この申請をした人を返す。
     *
     * @return BelongsTo この申請をした人
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * この申請と同じ人と日付の勤怠を返す。なければ null を返す。
     * 申請は勤怠がない日にも出せるように勤怠の番号を持たず、人と日付で勤怠とつながるので、リレーションではなく探して読む。
     *
     * @return AttendanceRecord|null この申請と同じ人と日付の勤怠
     */
    public function findAttendanceRecord(): ?AttendanceRecord
    {
        return AttendanceRecord::where('user_id', $this->user_id)
            ->where('date', $this->date)
            ->first();
    }

    /**
     * この申請で直したい休憩を、入りの早い順に返す。
     *
     * @return HasMany この申請で直したい休憩の一覧
     */
    public function proposalBreaks(): HasMany
    {
        return $this->hasMany(ProposalBreak::class)->orderBy('break_in');
    }

    /**
     * 申請日時の新しい順に並べ、同じ日時は id の大きい順にして、並びを1通りにする。
     *
     * @param  Builder<Application>  $query  並べる前の申請のクエリ
     * @return void 返す値はない
     */
    public function scopeLatestApplied(Builder $query): void
    {
        $query->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * 申請一覧の画面に渡す、渡された人の申請の行を返す。行は申請日時の新しい順に並べる。
     *
     * @param  User  $user  申請を一覧にする人
     * @return array<int, array<string, mixed>> approval_status、date、application_date、comment、id を持つ行
     */
    public static function listRowsFor(User $user): array
    {
        return $user->applications()
            ->latestApplied()
            ->get()
            ->map(fn (Application $application): array => [
                'approval_status' => $application->approval_status,
                'date' => Carbon::parse($application->date)->format('Y/m/d'),
                'application_date' => $application->created_at->format('Y/m/d'),
                'comment' => $application->comment,
                'id' => $application->id,
            ])
            ->all();
    }

    /**
     * 管理者の申請一覧の画面に渡す、全ユーザーの申請を返す。申請日時の新しい順に並べ、申請した人を一緒に読む。
     *
     * @return Collection<int, Application> 全ユーザーの申請
     */
    public static function allForAdminList(): Collection
    {
        return self::with('user')
            ->latestApplied()
            ->get();
    }

    /**
     * 申請詳細の画面に渡す値を返す。日付、時刻、休憩、備考は申請から作る。休憩は入りの早い順に、0からの連番で並べる。
     *
     * @return array<string, mixed> id（同じ人と日付の勤怠の id。勤怠がなければ null）、application、year、date、full_date（フォームの送り先に使う Y-m-d の日付）、clock_in、clock_out、breaks、comment
     */
    public function toDetailData(): array
    {
        $detailDate = TimeFormat::toDetailDate($this->date);

        return [
            'id' => $this->findAttendanceRecord()?->id,
            'application' => $this,
            'year' => $detailDate['year'],
            'date' => $detailDate['date'],
            'full_date' => $this->date,
            'clock_in' => $this->new_clock_in,
            'clock_out' => $this->new_clock_out,
            'breaks' => $this->proposalBreaks
                ->map(fn (ProposalBreak $break): array => $break->toBreakRow())
                ->all(),
            'comment' => $this->comment,
        ];
    }

    /**
     * 渡された勤怠の人と日付で、修正申請と申請の休憩を作る。勤怠がない日は、保存していない勤怠を渡す。
     * 申請と申請の休憩の書き込みを1つのトランザクションにまとめ、どちらかが失敗したら両方を元に戻す。入りと戻りが両方空の休憩の行は、toBreakRows() で除く。
     *
     * @param  AttendanceRecord  $attendanceRecord  申請する日の勤怠（勤怠がない日は保存していない勤怠）
     * @param  array{new_clock_in: string, new_clock_out: string, new_break_in?: array<int, string|null>, new_break_out?: array<int, string|null>, comment: string}  $validated  バリデーションを通った入力
     * @return void 返す値はない（作った申請と申請の休憩を保存する）
     */
    public static function submit(AttendanceRecord $attendanceRecord, array $validated): void
    {
        DB::transaction(function () use ($attendanceRecord, $validated): void {
            $application = self::create([
                'user_id' => $attendanceRecord->user_id,
                'date' => $attendanceRecord->date,
                'new_clock_in' => $validated['new_clock_in'],
                'new_clock_out' => $validated['new_clock_out'],
                'comment' => $validated['comment'],
            ]);

            $application->proposalBreaks()->createMany(
                AttendanceRecord::toBreakRows($validated['new_break_in'] ?? [], $validated['new_break_out'] ?? []),
            );
        });
    }

    /**
     * 修正申請を承認する。申請の出勤、退勤、備考と休憩で申請先の勤怠を書き換え、申請を承認済みにする。
     * 勤怠がない日の申請なら、申請の出勤で勤怠を作ってから書き換える。
     * 勤怠の作成と書き換えと申請の書き込みを1つのトランザクションにまとめ、どれかが失敗したら全部を元に戻す。
     *
     * @return void 返す値はない（書き換えた勤怠と承認済みにした申請を保存する）
     */
    public function approve(): void
    {
        DB::transaction(function (): void {
            AttendanceRecord::firstOrCreate(
                ['user_id' => $this->user_id, 'date' => $this->date],
                ['clock_in' => $this->new_clock_in],
            )->rewrite(
                $this->new_clock_in,
                $this->new_clock_out,
                $this->comment,
                $this->proposalBreaks
                    ->map(fn (ProposalBreak $break): array => $break->toBreakRow())
                    ->all(),
            );

            $this->is_approved = true;
            $this->save();
        });
    }

    /**
     * 承認の状態を、承認待ちか承認済みの文字で返すアクセサ。approval_status の名前で読む。
     *
     * @return Attribute<string, never> 承認の状態の文字
     */
    protected function approvalStatus(): Attribute
    {
        return Attribute::make(
            get: fn (): string => $this->is_approved ? '承認済み' : '承認待ち',
        );
    }

    /**
     * 直したい出勤を H:i の形で読むアクセサ。new_clock_in の名前で読む。
     *
     * @return Attribute<string, never> 直したい出勤（例：09:00）
     */
    protected function newClockIn(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => TimeFormat::toHourMinute($value),
        );
    }

    /**
     * 直したい退勤を H:i の形で読むアクセサ。new_clock_out の名前で読む。
     *
     * @return Attribute<string, never> 直したい退勤（例：18:00）
     */
    protected function newClockOut(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => TimeFormat::toHourMinute($value),
        );
    }

    /**
     * 申請の日付を Carbon で返すアクセサ。new_date の名前で読む。
     * 提供された Blade が申請の日付を new_date の名前で読み、日付を書き分けるので、date のカラムから Carbon にして返す。
     *
     * @return Attribute<Carbon, never> 申請の日付
     */
    protected function newDate(): Attribute
    {
        return Attribute::make(
            get: fn (): Carbon => Carbon::parse($this->date),
        );
    }

    /**
     * 申請日時を返すアクセサ。application_date の名前で読む。
     * 申請の行を作った日時が申請日時なので、created_at を返す。
     *
     * @return Attribute<Carbon, never> 申請日時
     */
    protected function applicationDate(): Attribute
    {
        return Attribute::make(
            get: fn (): Carbon => $this->created_at,
        );
    }
}
