<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

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
     * この申請で直したい休憩を返す。
     *
     * @return HasMany この申請で直したい休憩の一覧
     */
    public function proposalBreaks(): HasMany
    {
        return $this->hasMany(ProposalBreak::class);
    }
}
