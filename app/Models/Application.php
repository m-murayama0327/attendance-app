<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Facades\DB;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'attendance_record_id',
        'new_clock_in',
        'new_clock_out',
        'comment',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * この申請の勤怠の持ち主のユーザーを返す。
     * 申請は user_id を持たないため、勤怠を通して読む。
     *
     * @return HasOneThrough この申請の勤怠の持ち主のユーザー
     */
    public function user(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, AttendanceRecord::class, 'id', 'id', 'attendance_record_id', 'user_id');
    }

    /**
     * この申請の勤怠を返す。
     * 提供された Blade が $application->AttendanceRecord の名前で読むため、名前を大文字で始める。
     *
     * @return BelongsTo この申請の勤怠
     */
    public function AttendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
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
     * 勤怠を通して申請を読むときは勤怠のテーブルにも同じ名前のカラムがあるので、申請のテーブルの名前を付けて並べる。
     *
     * @param  Builder<Application>  $query  並べる前の申請のクエリ
     * @return void 返す値はない
     */
    public function scopeLatestApplied(Builder $query): void
    {
        $query->orderByDesc($query->qualifyColumn('created_at'))
            ->orderByDesc($query->qualifyColumn('id'));
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
            ->with('AttendanceRecord')
            ->latestApplied()
            ->get()
            ->map(fn (Application $application): array => [
                'approval_status' => $application->approval_status,
                'date' => Carbon::parse($application->AttendanceRecord->date)->format('Y/m/d'),
                'application_date' => $application->created_at->format('Y/m/d'),
                'comment' => $application->comment,
                'id' => $application->id,
            ])
            ->all();
    }

    /**
     * 管理者の申請一覧の画面に渡す、全ユーザーの申請を返す。申請日時の新しい順に並べ、申請した人と申請先の勤怠を一緒に読む。
     *
     * @return Collection<int, Application> 全ユーザーの申請
     */
    public static function allForAdminList(): Collection
    {
        return self::with('user', 'AttendanceRecord')
            ->latestApplied()
            ->get();
    }

    /**
     * 申請の詳細の画面に渡す値を返す。日付は勤怠から、時刻、休憩、備考は申請から作る。休憩は入りの早い順に、0からの連番で並べる。
     *
     * @return array<string, mixed> id（勤怠の id）、application、year、date、clock_in、clock_out、breaks、comment
     */
    public function toDetailData(): array
    {
        $date = Carbon::parse($this->AttendanceRecord->date);

        return [
            'id' => $this->attendance_record_id,
            'application' => $this,
            'year' => $date->format('Y年'),
            'date' => $date->format('n月j日'),
            'clock_in' => $this->new_clock_in,
            'clock_out' => $this->new_clock_out,
            'breaks' => $this->proposalBreaks
                ->map(fn (ProposalBreak $break): array => [
                    'break_in' => $break->formattedBreakIn(),
                    'break_out' => $break->formattedBreakOut(),
                ])
                ->all(),
            'comment' => $this->comment,
        ];
    }

    /**
     * 修正申請を承認する。申請の出勤、退勤、備考と休憩で申請先の勤怠を書き換え、申請を承認済みにする。
     * 勤怠の書き換えと申請の書き込みを1つのトランザクションにまとめ、どちらかが失敗したら両方を元に戻す。
     *
     * @return void 返す値はない（書き換えた勤怠と承認済みにした申請を保存する）
     */
    public function approve(): void
    {
        DB::transaction(function (): void {
            $this->AttendanceRecord->rewrite(
                $this->new_clock_in,
                $this->new_clock_out,
                $this->comment,
                $this->proposalBreaks
                    ->map(fn (ProposalBreak $break): array => [
                        'break_in' => $break->formattedBreakIn(),
                        'break_out' => $break->formattedBreakOut(),
                    ])
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
            get: fn (string $value): string => substr($value, 0, 5),
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
            get: fn (string $value): string => substr($value, 0, 5),
        );
    }

    /**
     * 申請先の勤怠の日付を Carbon で返すアクセサ。new_date の名前で読む。
     * 申請の日付は勤怠詳細の画面で書き換えられず、申請先の勤怠の日付と同じなので、カラムを置かずに勤怠から作る。
     *
     * @return Attribute<Carbon, never> 申請の日付
     */
    protected function newDate(): Attribute
    {
        return Attribute::make(
            get: fn (): Carbon => Carbon::parse($this->AttendanceRecord->date),
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
