<?php

namespace Tests\Feature\App\Services;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Services\AttendanceReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceReportServiceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 他の一般ユーザーの勤怠は、集計に入らないことを確かめる。
     * 自分の 2026-11-02 の勤怠と他の一般ユーザーの 2026-11-02 の 09:00〜20:00 の勤怠があるときに、テストから 2026-11-16 に build() に自分を渡して呼び、build() が渡されたユーザーの勤怠だけを探して、summary の total_work_minutes が 480 で、total_overtime_minutes が 0 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_records_are_excluded(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-02', '09:00:00', '18:00:00');
        $this->createRecord($otherUser, '2026-11-02', '09:00:00', '20:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['total_work_minutes']);
        $this->assertSame(0, $report['summary']['total_overtime_minutes']);
    }

    /**
     * 今日が月の末日でも、範囲の初めの月の1日の勤怠が集計に入ることを確かめる。
     * 勤怠の持ち主の 2026-02-01 の勤怠があるときに、テストから 2026-07-31 に build() に勤怠の持ち主を渡して呼び、build() が月をあふれさせずに5か月前の月の1日から数えて、summary の total_work_minutes が 480 で、monthlyTrend の最初の月が 2026-02 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_range_does_not_overflow_on_month_end(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-02-01', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-07-31 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['total_work_minutes']);
        $this->assertSame('2026-02', $report['monthlyTrend'][0]['month']);
    }

    /**
     * 範囲の初めの月の1日の前日の勤怠は、集計に入らないことを確かめる。
     * 勤怠の持ち主の 2026-05-31 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、build() が範囲の初めの月の1日の前日の勤怠を数えずに、summary の total_work_minutes が 0 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_before_range_is_excluded(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-05-31', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(0, $report['summary']['total_work_minutes']);
    }

    /**
     * 範囲の初めの月の1日の勤怠も、集計に入ることを確かめる。
     * 勤怠の持ち主の 2026-06-01 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、build() が範囲の初めの月の1日の勤怠を数えて、summary の total_work_minutes が 480 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_first_day_of_range_is_included(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-06-01', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['total_work_minutes']);
    }

    /**
     * 今日より後の当月の末日の勤怠も、集計に入ることを確かめる。
     * 勤怠の持ち主の 2026-11-30 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、build() が今日より後の当月の末日の勤怠も数えて、summary の total_work_minutes が 480 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_last_day_of_month_is_included(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-30', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['total_work_minutes']);
    }

    /**
     * 当月の翌月の勤怠は、集計に入らないことを確かめる。
     * 勤怠の持ち主の 2026-12-01 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、build() が当月の翌月の1日の勤怠を数えずに、summary の total_work_minutes が 0 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_month_is_excluded(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-12-01', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(0, $report['summary']['total_work_minutes']);
    }

    /**
     * 今日が年の最初の月の日でも、前の年にある範囲の初めの月の1日の勤怠が集計に入ることを確かめる。
     * 勤怠の持ち主の 2026-08-01 の勤怠があるときに、テストから 2027-01-15 に build() に勤怠の持ち主を渡して呼び、build() が年をまたいで前の年の5か月前の月の1日から数えて、summary の total_work_minutes が 480 で、monthlyTrend の最初の月が 2026-08 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_range_crosses_year(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-08-01', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2027-01-15 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['total_work_minutes']);
        $this->assertSame('2026-08', $report['monthlyTrend'][0]['month']);
    }

    /**
     * マイ勤怠レポートの集計、月次推移、当月のフラグの数が要件の計算どおりになることを確かめる。
     * 2026-04〜2026-11 の8か月に休憩 12:00〜13:00 の勤怠（2026-04 と 2026-05 は 09:00〜18:00 の1日ずつ、2026-06 は 09:00〜20:00 の1日、2026-07 はなし、2026-08 は 09:00〜18:00 の2日、2026-09 は 09:30〜18:00 の1日、2026-10 は 09:00〜18:00 の1日、2026-11 は 09:30〜18:00、09:00〜17:00、08:00〜21:00 の3日）があるときに、一般ユーザーが 2026-11-16 に GET /attendance/report を開き、build() が当月を含む6か月の勤怠を数えて、$summary が合計 4080、残業 360、平均 510、$monthlyTrend が古い順に 2026-06（600、120）、2026-07（0、0）、2026-08（960、0）、2026-09（450、0）、2026-10（480、0）、2026-11（1590、240）、$anomalies が遅刻1、早退1、長時間労働1（2026-09 の遅刻は数えない）で、200 で reports.index を出すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_report_values_match_requirements(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-04-01', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-05-01', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-06-01', '09:00:00', '20:00:00');
        $this->createRecord($user, '2026-08-03', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-08-04', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-09-01', '09:30:00', '18:00:00');
        $this->createRecord($user, '2026-10-01', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-11-02', '09:30:00', '18:00:00');
        $this->createRecord($user, '2026-11-03', '09:00:00', '17:00:00');
        $this->createRecord($user, '2026-11-04', '08:00:00', '21:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', [
            'total_work_minutes' => 4080,
            'total_overtime_minutes' => 360,
            'avg_work_minutes' => 510,
        ]);
        $response->assertViewHas('monthlyTrend', [
            ['month' => '2026-06', 'work_minutes' => 600, 'overtime_minutes' => 120],
            ['month' => '2026-07', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-08', 'work_minutes' => 960, 'overtime_minutes' => 0],
            ['month' => '2026-09', 'work_minutes' => 450, 'overtime_minutes' => 0],
            ['month' => '2026-10', 'work_minutes' => 480, 'overtime_minutes' => 0],
            ['month' => '2026-11', 'work_minutes' => 1590, 'overtime_minutes' => 240],
        ]);
        $response->assertViewHas('anomalies', [
            'late_count' => 1,
            'early_leave_count' => 1,
            'long_work_count' => 1,
        ]);
        $response->assertViewIs('reports.index');
    }

    /**
     * 勤怠がないユーザーは、集計の値が全部0になることを確かめる。
     * 勤怠のない一般ユーザーが 2026-11-16 に GET /attendance/report を開き、summary() が0で割らずに平均を0にして、$summary の3つの値が全部0で、200 で reports.index を出し、$monthlyTrend が値0の6行、$anomalies の3つの値が全部0かを見る。
     *
     * @return void 返す値はない
     */
    public function test_report_is_zero_without_records(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', [
            'total_work_minutes' => 0,
            'total_overtime_minutes' => 0,
            'avg_work_minutes' => 0,
        ]);
        $response->assertViewIs('reports.index');
        $response->assertViewHas('monthlyTrend', [
            ['month' => '2026-06', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-07', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-08', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-09', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-10', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-11', 'work_minutes' => 0, 'overtime_minutes' => 0],
        ]);
        $response->assertViewHas('anomalies', [
            'late_count' => 0,
            'early_leave_count' => 0,
            'long_work_count' => 0,
        ]);
    }

    /**
     * 退勤がない日は、平均の勤務時間を出す日数に入らないことを確かめる。
     * 2026-11-02 の 09:00〜18:00、2026-11-03 の 09:00〜19:00、2026-11-04 の 09:00 出勤で退勤も休憩もない勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、summary() が work_minutes が null の退勤のない日を平均の日数に入れずに、summary の avg_work_minutes が 510 で、total_work_minutes が 1020、total_overtime_minutes が 60 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_average_ignores_day_without_clock_out(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-02', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-11-03', '09:00:00', '19:00:00');
        $this->createRecord($user, '2026-11-04', '09:00:00', null, false);
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(510, $report['summary']['avg_work_minutes']);
        $this->assertSame(1020, $report['summary']['total_work_minutes']);
        $this->assertSame(60, $report['summary']['total_overtime_minutes']);
    }

    /**
     * 平均の勤務時間の小数は、切り捨てられることを確かめる。
     * 2026-11-02 の 09:00〜18:00 と 2026-11-03 の 09:00〜18:01 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、summary() が平均の小数を切り捨てて、summary の avg_work_minutes が 480 で、total_work_minutes が 961、total_overtime_minutes が 1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_average_is_truncated(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-02', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-11-03', '09:00:00', '18:01:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(480, $report['summary']['avg_work_minutes']);
        $this->assertSame(961, $report['summary']['total_work_minutes']);
        $this->assertSame(1, $report['summary']['total_overtime_minutes']);
    }

    /**
     * 勤怠がない月も、月次推移の行に入ることを確かめる。
     * 2026-08-03 だけに勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、monthlyTrend() が勤怠のない月も0の行にして6か月を古い順に並べ、monthlyTrend が 2026-06〜2026-11 の古い順の6行で、2026-08 の work_minutes だけが 480、他の値が0かを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_trend_fills_empty_months(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-08-03', '09:00:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame([
            ['month' => '2026-06', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-07', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-08', 'work_minutes' => 480, 'overtime_minutes' => 0],
            ['month' => '2026-09', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-10', 'work_minutes' => 0, 'overtime_minutes' => 0],
            ['month' => '2026-11', 'work_minutes' => 0, 'overtime_minutes' => 0],
        ], $report['monthlyTrend']);
    }

    /**
     * 同じ月の勤怠の勤務時間と残業時間は、月次推移で足されることを確かめる。
     * 2026-08-03 の 09:00〜18:00 と 2026-08-04 の 09:00〜20:00 の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、monthlyTrend() が同じ月の勤務時間と残業時間を足して、monthlyTrend の 2026-08 の行の work_minutes が 1080、overtime_minutes が 120 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_trend_sums_same_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-08-03', '09:00:00', '18:00:00');
        $this->createRecord($user, '2026-08-04', '09:00:00', '20:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $august = collect($report['monthlyTrend'])->firstWhere('month', '2026-08');
        $this->assertSame(1080, $august['work_minutes']);
        $this->assertSame(120, $august['overtime_minutes']);
    }

    /**
     * 今日が月の末日でも、月次推移の月が重ならないことを確かめる。
     * テストから 2026-07-31 に勤怠のない一般ユーザーを渡して build() を呼び、monthlyTrend() が月をあふれさせずに6か月の月の名前を作って、monthlyTrend の month が古い順に 2026-02〜2026-07 の重ならない6つかを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_trend_does_not_overflow(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-07-31 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(
            ['2026-02', '2026-03', '2026-04', '2026-05', '2026-06', '2026-07'],
            array_column($report['monthlyTrend'], 'month'),
        );
    }

    /**
     * 今日が年の最初の月の日でも、月次推移に前の年の月が入ることを確かめる。
     * テストから 2027-01-15 に勤怠のない一般ユーザーを渡して build() を呼び、monthlyTrend() が年をまたいで前の年の月の名前を作って、monthlyTrend の month が古い順に 2026-08〜2027-01 の6つかを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_trend_crosses_year(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2027-01-15 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(
            ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'],
            array_column($report['monthlyTrend'], 'month'),
        );
    }

    /**
     * 当月の勤怠のフラグは、フラグごとに別々に数えられることを確かめる。
     * 当月に 09:30〜18:00 の2日、09:00〜17:00 の1日、08:00〜21:00 の1日の勤怠があるときに、テストから 2026-11-16 に build() に勤怠の持ち主を渡して呼び、anomalies() が3つのフラグを別々に数えて、anomalies の late_count が 2、early_leave_count が 1、long_work_count が 1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_anomalies_count_each_flag(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-02', '09:30:00', '18:00:00');
        $this->createRecord($user, '2026-11-03', '09:30:00', '18:00:00');
        $this->createRecord($user, '2026-11-04', '09:00:00', '17:00:00');
        $this->createRecord($user, '2026-11-05', '08:00:00', '21:00:00');
        $this->travelTo(Carbon::parse('2026-11-16 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(2, $report['anomalies']['late_count']);
        $this->assertSame(1, $report['anomalies']['early_leave_count']);
        $this->assertSame(1, $report['anomalies']['long_work_count']);
    }

    /**
     * 今日が月の1日のとき、前の月の末日の遅刻は数えられないことを確かめる。
     * 勤怠の持ち主の 2026-10-31 の 09:30〜18:00 の勤怠があるときに、テストから 2026-11-01 に build() に勤怠の持ち主を渡して呼び、anomalies() が前の月の末日の遅刻を数えずに、anomalies の late_count が 0 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_previous_month_anomaly_is_excluded(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-10-31', '09:30:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(0, $report['anomalies']['late_count']);
    }

    /**
     * 今日が月の1日のとき、当月の1日の遅刻は数えられることを確かめる。
     * 勤怠の持ち主の 2026-11-01 の 09:30〜18:00 の勤怠があるときに、テストから 2026-11-01 に build() に勤怠の持ち主を渡して呼び、anomalies() が当月の1日の遅刻を数えて、anomalies の late_count が 1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_first_day_anomaly_is_counted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createRecord($user, '2026-11-01', '09:30:00', '18:00:00');
        $this->travelTo(Carbon::parse('2026-11-01 10:00:00'));

        $report = (new AttendanceReportService)->build($user);

        $this->assertSame(1, $report['anomalies']['late_count']);
    }

    /**
     * 渡したユーザーと日付の勤怠を渡した出勤と退勤で作り、休憩 12:00〜13:00 を1行付けて recalculate() で計算する。
     *
     * @param  User  $user  勤怠の持ち主の一般ユーザー
     * @param  string  $date  勤怠の日付（例：2026-11-02）
     * @param  string  $clockIn  出勤（例：09:00:00）
     * @param  string|null  $clockOut  退勤（退勤がないときは null）
     * @param  bool  $hasBreak  休憩 12:00〜13:00 を付けるなら true
     * @return void 返す値はない
     */
    private function createRecord(User $user, string $date, string $clockIn, ?string $clockOut, bool $hasBreak = true): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => $date,
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);

        if ($hasBreak) {
            AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        }

        $attendanceRecord->recalculate();
    }
}
