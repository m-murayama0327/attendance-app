<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    private function toMinutes(string $time): int
    {
        // 画面に出る時刻と計算の結果を合わせるため、秒は切り捨てる
        [$hours, $minutes] = explode(':', $time);

        return (int) $hours * 60 + (int) $minutes;
    }
}
