<?php

namespace App\Models;

use App\Models\Concerns\HasBreakTimes;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AttendanceBreak extends Model
{
    use HasBreakTimes, HasFactory;

    // 要件のテーブルの名前は breaks で、クラスの名前から決まる attendance_breaks と違うため、指定する
    protected $table = 'breaks';

    protected $fillable = [
        'attendance_record_id',
        'break_in',
        'break_out',
    ];

    /**
     * 戻りのない休憩（今とっている休憩）だけに絞る。
     *
     * @param  Builder<AttendanceBreak>  $query  絞る前の休憩のクエリ
     * @return void 返す値はない
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('break_out');
    }
}
