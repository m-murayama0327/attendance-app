<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceBreak extends Model
{
    use HasFactory;

    // 要件のテーブルの名前は breaks で、クラスの名前から決まる attendance_breaks と違うため、指定する
    protected $table = 'breaks';

    protected $fillable = [
        'attendance_record_id',
        'break_in',
        'break_out',
    ];

    /**
     * この休憩の勤怠を返す。
     *
     * @return BelongsTo この休憩の勤怠
     */
    public function attendanceRecord(): BelongsTo
    {
        return $this->belongsTo(AttendanceRecord::class);
    }
}
