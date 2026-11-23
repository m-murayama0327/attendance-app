<?php

namespace Tests\Feature\App\Http\Resources;

use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 勤怠の詳細で、申請の出勤と退勤が秒まで付いた時刻で返ることを確かめる。
     * 一般ユーザーの勤怠（2026-05-01、出勤 09:00:00、退勤 18:00:00）と同じ人と日付の承認待ちの申請（出勤 08:30:00、退勤 17:30:00、備考「打刻の時刻を誤ったため」）があるときに、ログインしていない人が GET /api/v1/attendance-records/{id} を開き、toArray() が getRawOriginal() でデータベースの値を選んで、200 で data の applications の1行目の new_clock_in が 08:30:00、new_clock_out が 17:30:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_times_are_returned_with_seconds(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-05-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '17:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response = $this->getJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertJsonPath('data.applications.0.new_clock_in', '08:30:00');
        $response->assertJsonPath('data.applications.0.new_clock_out', '17:30:00');
    }
}
