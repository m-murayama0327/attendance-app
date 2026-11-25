<?php

namespace App\Services;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AttendanceReportService
{
    // 要件で決まった集計の期間で、当月を含むこの月数の勤怠を集計する。
    private const MONTH_COUNT = 6;

    /**
     * 渡されたユーザーの当月を含む6か月の勤怠を1回のクエリで読み、マイ勤怠レポートの3つの集計を返す。
     * 今日の日付は最初に1回だけ取り、3つの集計に渡す。こうすると、集計の途中で日付が変わっても、読む範囲と出す月が食い違わない。
     *
     * @param  User  $user  集計するユーザー
     * @return array{summary: array<string, int>, monthlyTrend: array<int, array<string, int|string>>, anomalies: array<string, int>} 画面に渡す3つの集計
     */
    public function build(User $user): array
    {
        $today = Carbon::today();
        $start = $today->copy()->subMonthsNoOverflow(self::MONTH_COUNT - 1)->startOfMonth();
        $end = $today->copy()->endOfMonth();

        $attendanceRecords = $user->attendanceRecords()
            ->betweenDates($start, $end)
            ->get(['date', 'work_minutes', 'overtime_minutes', 'is_late_arrival', 'is_early_departure', 'is_long_work']);

        return [
            'summary' => $this->summary($attendanceRecords),
            'monthlyTrend' => $this->monthlyTrend($attendanceRecords, $today),
            'anomalies' => $this->anomalies($attendanceRecords, $today),
        ];
    }

    /**
     * 6か月の勤務時間の合計、残業時間の合計、勤務時間がある日の勤務時間の平均（小数を切り捨てた分）を返す。勤怠がなければ全部0を返す。
     *
     * @param  Collection<int, AttendanceRecord>  $attendanceRecords  6か月の勤怠
     * @return array<string, int> 合計の勤務時間、合計の残業時間、平均の勤務時間（どれも分）
     */
    private function summary(Collection $attendanceRecords): array
    {
        $workedRecords = $attendanceRecords->whereNotNull('work_minutes');

        return [
            'total_work_minutes' => (int) $attendanceRecords->sum('work_minutes'),
            'total_overtime_minutes' => (int) $attendanceRecords->sum('overtime_minutes'),
            'avg_work_minutes' => $workedRecords->isEmpty() ? 0 : intdiv((int) $workedRecords->sum('work_minutes'), $workedRecords->count()),
        ];
    }

    /**
     * 勤怠を日付の年と月で分け、当月を含む6か月の月ごとの勤務時間と残業時間を古い順に返す。勤怠のない月は0を返す。
     *
     * @param  Collection<int, AttendanceRecord>  $attendanceRecords  6か月の勤怠
     * @param  Carbon  $today  `build()` が最初に1回だけ取った今日の日付
     * @return array<int, array<string, int|string>> 月ごとの行（month は `2026-05` の形、work_minutes と overtime_minutes は分）
     */
    private function monthlyTrend(Collection $attendanceRecords, Carbon $today): array
    {
        $recordsByMonth = $attendanceRecords->groupBy(fn (AttendanceRecord $attendanceRecord): string => substr($attendanceRecord->date, 0, 7));

        return collect(range(self::MONTH_COUNT - 1, 0))
            ->map(function (int $monthsAgo) use ($recordsByMonth, $today): array {
                $month = $today->copy()->subMonthsNoOverflow($monthsAgo)->format('Y-m');
                $monthRecords = $recordsByMonth->get($month, collect());

                return [
                    'month' => $month,
                    'work_minutes' => (int) $monthRecords->sum('work_minutes'),
                    'overtime_minutes' => (int) $monthRecords->sum('overtime_minutes'),
                ];
            })
            ->all();
    }

    /**
     * 当月の勤怠のうち、遅刻、早退、長時間労働の日を数えて返す。
     *
     * @param  Collection<int, AttendanceRecord>  $attendanceRecords  6か月の勤怠
     * @param  Carbon  $today  `build()` が最初に1回だけ取った今日の日付
     * @return array<string, int> 遅刻、早退、長時間労働の日の数
     */
    private function anomalies(Collection $attendanceRecords, Carbon $today): array
    {
        $currentMonth = $today->format('Y-m');
        $currentMonthRecords = $attendanceRecords->filter(fn (AttendanceRecord $attendanceRecord): bool => str_starts_with($attendanceRecord->date, $currentMonth));

        return [
            'late_count' => $currentMonthRecords->where('is_late_arrival', true)->count(),
            'early_leave_count' => $currentMonthRecords->where('is_early_departure', true)->count(),
            'long_work_count' => $currentMonthRecords->where('is_long_work', true)->count(),
        ];
    }
}
