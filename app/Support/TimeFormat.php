<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * 時刻と日付を、画面に出す形の文字にする。勤怠、休憩、申請、申請の休憩のモデルで使う。
 */
class TimeFormat
{
    /**
     * 時刻の文字（例：09:00:30）を「時:分」の文字（例：09:00）にして返す。時刻がなければ null を返す。
     *
     * @param  string|null  $time  時刻の文字（ないときは null）
     * @return string|null 「時:分」の文字（時刻がないときは null）
     */
    public static function toHourMinute(?string $time): ?string
    {
        return $time === null ? null : substr($time, 0, 5);
    }

    /**
     * 日付の文字（例：2026-06-01）から、詳細の画面に出す年（例：2026年）と月日（例：6月1日）を返す。
     *
     * @param  string  $date  日付の文字
     * @return array{year: string, date: string} 年と月日
     */
    public static function toDetailDate(string $date): array
    {
        $carbonDate = Carbon::parse($date);

        return [
            'year' => $carbonDate->format('Y年'),
            'date' => $carbonDate->format('n月j日'),
        ];
    }
}
