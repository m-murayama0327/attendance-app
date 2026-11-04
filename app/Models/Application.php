<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Application extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'attendance_record_id',
        'new_clock_in',
        'new_clock_out',
        'comment',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
    ];

    /**
     * この申請を出したユーザーを返す。
     *
     * @return BelongsTo この申請を出したユーザー
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
