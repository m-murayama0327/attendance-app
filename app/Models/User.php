<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

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
     * 申請は user_id を持たないため、このユーザーの勤怠を通して読む。
     *
     * @return HasManyThrough このユーザーの修正申請の一覧
     */
    public function applications(): HasManyThrough
    {
        return $this->hasManyThrough(Application::class, AttendanceRecord::class);
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
     * 今日の勤怠を返す。今日の行がなければ null を返す。
     *
     * @return AttendanceRecord|null 今日の勤怠
     */
    public function findTodayAttendanceRecord(): ?AttendanceRecord
    {
        return $this->attendanceRecords()->where('date', today()->toDateString())->first();
    }

    /**
     * 今日の勤怠と休憩から、勤務外、出勤中、休憩中、退勤済のどれかを返す（`$user->attendance_status` で読む）。
     * 打刻の直後に古い状態を出さないよう、読むたびにデータベースを読む。
     *
     * @return Attribute<string, never> 打刻の状態の文字
     */
    protected function attendanceStatus(): Attribute
    {
        return Attribute::get(function (): string {
            $record = $this->findTodayAttendanceRecord();

            if ($record === null) {
                return '勤務外';
            }

            if ($record->clock_out !== null) {
                return '退勤済';
            }

            if ($record->breaks()->whereNull('break_out')->exists()) {
                return '休憩中';
            }

            return '出勤中';
        });
    }
}
