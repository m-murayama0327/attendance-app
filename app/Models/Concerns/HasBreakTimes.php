<?php

namespace App\Models\Concerns;

use App\Support\TimeFormat;

/**
 * break_in と break_out のカラムを持つ休憩のモデル（AttendanceBreak、ProposalBreak）で、休憩の時刻を画面に出す形にする。
 */
trait HasBreakTimes
{
    /**
     * 休憩を、詳細の画面と勤怠の書き換えで使う、入りと戻りの「時:分」の配列にして返す。
     *
     * @return array{break_in: string, break_out: string|null} 休憩の入りと戻り（例：['break_in' => '12:00', 'break_out' => '13:00']）
     */
    public function toBreakRow(): array
    {
        return [
            'break_in' => TimeFormat::toHourMinute($this->break_in),
            'break_out' => TimeFormat::toHourMinute($this->break_out),
        ];
    }
}
