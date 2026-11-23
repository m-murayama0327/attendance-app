<?php

namespace Tests\Feature\App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 承認済みの申請をもう一度承認しても、勤怠が 09:00〜18:00 のまま変わらないことを確かめる。
     * 承認済みの申請（08:30〜18:30）の勤怠を 09:00〜18:00 に直したときに、管理者が POST /stamp_correction_request/approve/{id} を送り、approve() が承認し直さずに、/stamp_correction_request/approve/{id} に移り、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_application_is_ignored(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'is_approved' => true,
        ]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $response->assertRedirect('/stamp_correction_request/approve/'.$application->id);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }
}
