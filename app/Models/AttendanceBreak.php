<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
     * 戻りのない休憩（今とっている休憩）だけに絞る。
     *
     * @param  Builder<AttendanceBreak>  $query  絞る前の休憩のクエリ
     * @return void 返す値はない
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('break_out');
    }

    /**
     * 休憩の入りを H:i の文字で返す。
     *
     * @return string 休憩の入り（例：12:00）
     */
    public function formattedBreakIn(): string
    {
        return substr($this->break_in, 0, 5);
    }

    /**
     * 休憩の戻りを H:i の文字で返す。戻りがなければ null を返す。
     *
     * @return string|null 休憩の戻り（例：13:00）
     */
    public function formattedBreakOut(): ?string
    {
        return $this->break_out === null ? null : substr($this->break_out, 0, 5);
    }
}
