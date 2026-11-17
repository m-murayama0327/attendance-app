<?php

namespace App\Models;

use App\Providers\RouteServiceProvider;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens, HasFactory, Notifiable;

    // 提供された Blade が打刻の状態をこの文字で比べるので、値の文字は変えない
    public const STATUS_OFF_DUTY = '勤務外';

    public const STATUS_WORKING = '出勤中';

    public const STATUS_ON_BREAK = '休憩中';

    public const STATUS_FINISHED = '退勤済';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'admin_status' => 'boolean',
    ];

    /**
     * このユーザーの勤怠を返す。
     *
     * @return HasMany このユーザーの勤怠の一覧
     */
    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * このユーザーの修正申請を返す。
     *
     * @return HasMany このユーザーの修正申請の一覧
     */
    public function applications(): HasMany
    {
        return $this->hasMany(Application::class);
    }

    /**
     * 管理者を除き、一般ユーザーだけに絞る。
     *
     * @param  Builder<User>  $query  絞る前のユーザーのクエリ
     * @return void 返す値はない
     */
    public function scopeGeneral(Builder $query): void
    {
        $query->where('admin_status', false);
    }

    /**
     * ユーザーを登録順（id の昇順）に並べる。
     *
     * @param  Builder<User>  $query  並べる前のユーザーのクエリ
     * @return void 返す値はない
     */
    public function scopeOrderByRegistration(Builder $query): void
    {
        $query->orderBy('id');
    }

    /**
     * 今日の勤怠を返す。今日の行がなければ null を返す。
     *
     * @return AttendanceRecord|null 今日の勤怠
     */
    public function findTodayAttendanceRecord(): ?AttendanceRecord
    {
        return $this->attendanceRecords()->where('date', today()->toDateString())->first();
    }

    /**
     * 今日の勤怠と休憩から、勤務外、出勤中、休憩中、退勤済のどれかを返す。
     * 打刻の状態の表示と打刻の処理が、同じ決め方を使うためのメソッド。
     *
     * @param  AttendanceRecord|null  $attendanceRecord  今日の勤怠（今日の行がなければ null）
     * @return string 打刻の状態の文字（STATUS_ で始まる定数のどれか）
     */
    public static function resolveAttendanceStatus(?AttendanceRecord $attendanceRecord): string
    {
        if ($attendanceRecord === null) {
            return self::STATUS_OFF_DUTY;
        }

        if ($attendanceRecord->clock_out !== null) {
            return self::STATUS_FINISHED;
        }

        if ($attendanceRecord->breaks()->open()->exists()) {
            return self::STATUS_ON_BREAK;
        }

        return self::STATUS_WORKING;
    }

    /**
     * ログインした後とトップページで移す先のパスを返す。管理者は勤怠一覧画面（管理者）、一般ユーザーは打刻画面にする。
     *
     * @return string 移す先のパス
     */
    public function homePath(): string
    {
        return $this->admin_status ? '/admin/attendance/list' : RouteServiceProvider::HOME;
    }

    /**
     * 今日の勤怠と休憩から、勤務外、出勤中、休憩中、退勤済のどれかを返す（`$user->attendance_status` で読む）。
     * 打刻の直後に古い状態を出さないよう、読むたびにデータベースを読む。
     *
     * @return Attribute<string, never> 打刻の状態の文字
     */
    protected function attendanceStatus(): Attribute
    {
        return Attribute::get(fn (): string => self::resolveAttendanceStatus($this->findTodayAttendanceRecord()));
    }
}
