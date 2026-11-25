<?php

namespace Tests\Feature\App\Models;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceBreakTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 休憩の戻りがあるなら、打刻の画面に「出勤中」が出ることを確かめる。
     * 09:00 出勤の今日の勤怠に 12:00〜13:00 の休憩だけがあるときに、一般ユーザーが GET /attendance を開き、scopeOpen() が戻りのある休憩を除いて、200 でボタン clock_out と break_in だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_status_after_break(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 14:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('出勤中');
        $response->assertSee('value="clock_out"', false);
        $response->assertSee('value="break_in"', false);
        $response->assertDontSee('value="break_out"', false);
        $response->assertDontSee('value="clock_in"', false);
    }

    /**
     * 休憩の戻りがないなら、打刻の画面に「休憩中」が出ることを確かめる。
     * 今日の 09:00 出勤の勤怠に 12:00 からの戻りのない休憩があるときに、一般ユーザーが 12:30 に GET /attendance を開き、scopeOpen() が戻りのない休憩を残して、200 でボタン break_out だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_status_on_break(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 12:30:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => null]);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('休憩中');
        $response->assertSee('value="break_out"', false);
        $response->assertDontSee('value="clock_out"', false);
        $response->assertDontSee('value="break_in"', false);
        $response->assertDontSee('value="clock_in"', false);
    }
}
