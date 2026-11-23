<?php

namespace Tests\Feature\App\Http\Controllers;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 一般ユーザーがマイ勤怠レポートを開くと、自分の勤怠の集計が画面に渡ることを確かめる。
     * 開いた人の 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00、work_minutes 480）があるときに、一般ユーザーが 2026-06-15 に GET /attendance/report を開き、index() が開いた人の集計を AttendanceReportService.php の build() で作って画面に渡し、200 で reports.index が返り、summary の total_work_minutes が 480 で、monthlyTrend と anomalies もあるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_report_passes_summary_to_view(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $attendanceRecord->recalculate();

        $response = $this->actingAs($user)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', fn (array $summary): bool => $summary['total_work_minutes'] === 480);
        $response->assertViewHas('monthlyTrend');
        $response->assertViewHas('anomalies');
        $response->assertViewIs('reports.index');
    }

    /**
     * 他の一般ユーザーの勤怠だけがあると、マイ勤怠レポートの集計に他の人の勤怠を入れないことを確かめる。
     * 一般ユーザー A の 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00、work_minutes 480）だけがあるときに、一般ユーザー B が 2026-06-15 に GET /attendance/report を開き、index() がログインしている人（$request->user()）を build() に渡して、200 で reports.index が返り、summary の total_work_minutes が 0 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_report_uses_logged_in_user(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 12:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $attendanceRecord->recalculate();

        $response = $this->actingAs($otherUser)->get('/attendance/report');

        $response->assertOk();
        $response->assertViewHas('summary', fn (array $summary): bool => $summary['total_work_minutes'] === 0);
        $response->assertViewIs('reports.index');
    }
}
