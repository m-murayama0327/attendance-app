<?php

namespace Tests\Feature\App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AttendanceRecordPolicyTest extends TestCase
{
    use RefreshDatabase;

    // 3つのテストが勤怠詳細から送る値。休憩のない勤怠（09:00〜18:00）に休憩1行を足すので、備考は「休憩の打刻漏れのため」にする。
    private const DETAIL_VALUES = [
        'new_clock_in' => '09:00',
        'new_clock_out' => '18:00',
        'new_break_in' => ['12:00'],
        'new_break_out' => ['13:00'],
        'comment' => '休憩の打刻漏れのため',
    ];

    // 2つのテストが公開API の更新で送る値。09:00:00〜18:00:00 の勤怠の出勤を 09:30:00 に直す。時刻を変える直しなので、備考は判断事項一覧の No 301 の「打刻の時刻を誤ったため」にする。
    private const API_VALUES = [
        'date' => '2026-06-01',
        'clock_in' => '09:30:00',
        'clock_out' => '18:00:00',
        'comment' => '打刻の時刻を誤ったため',
    ];

    /**
     * 操作する人が管理者なら、一般ユーザーの勤怠に備考と休憩を直接書くことを確かめる。
     * 管理者が一般ユーザーの休憩のない勤怠に POST /attendance/{id} で休憩1行と備考を送り、before() が管理者に許して、勤怠に備考と休憩が入り、申請ができずに /attendance/{id} に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_is_updated_directly(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);

        $response = $this->actingAs($admin)->post('/attendance/'.$attendanceRecord->id, self::DETAIL_VALUES);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'comment' => '休憩の打刻漏れのため',
        ]);
        $this->assertDatabaseHas('breaks', [
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 管理者は、公開API で一般ユーザーの勤怠を消せることを確かめる。
     * 管理者が Sanctum::actingAs() で一般ユーザーの勤怠（2026-06-01、09:00:00〜18:00:00）を DELETE /api/v1/attendance-records/{id} で消し、before() が管理者なら delete() を調べずに許して、勤怠が消え、204 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_can_delete_via_api(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);
        Sanctum::actingAs($admin);

        $response = $this->deleteJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertNoContent();
        $this->assertModelMissing($attendanceRecord);
    }

    /**
     * 開く人が勤怠の持ち主なら、200 で user.user-detail の画面を出すことを確かめる。
     * 一般ユーザーが自分の勤怠（2026-06-01、09:00〜18:00）を GET /attendance/{id} で開き、view() が持ち主に見ることを許して、200 で user.user-detail を出すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_view(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
    }

    /**
     * 開く人が他の一般ユーザーなら、403 になることを確かめる。
     * 一般ユーザーが他の人の勤怠（2026-06-01、09:00〜18:00）を GET /attendance/{id} で開き、view() が持ち主でない一般ユーザーに見ることを許さず、403 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($otherUser);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertForbidden();
    }

    /**
     * 送る人が勤怠の持ち主なら、申請が1行でき、/attendance/{id} へ戻ることを確かめる。
     * 一般ユーザーが自分の休憩のない勤怠に POST /attendance/{id} で休憩1行と備考を送り、update() が持ち主に許して、2026-06-01 の申請が1行でき、/attendance/{id} へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_update(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, self::DETAIL_VALUES);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseHas('applications', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'comment' => '休憩の打刻漏れのため',
        ]);
    }

    /**
     * 送る人が他の一般ユーザーなら、403 になり、申請ができないことを確かめる。
     * 一般ユーザーが他の人の休憩のない勤怠に POST /attendance/{id} で休憩1行と備考を送り、update() が持ち主でない一般ユーザーに許さず、403 になり、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden_on_request(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($otherUser);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, self::DETAIL_VALUES);

        $response->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 勤怠の持ち主は、公開API で自分の勤怠を更新できることを確かめる。
     * 一般ユーザーが Sanctum::actingAs() で自分の勤怠（2026-06-01、09:00:00〜18:00:00）に PUT /api/v1/attendance-records/{id} で date 2026-06-01、clock_in 09:30:00、clock_out 18:00:00、comment「打刻の時刻を誤ったため」を送り、update() が持ち主に許して、勤怠の clock_in が 09:30:00、comment が「打刻の時刻を誤ったため」に変わり、200 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_update_via_api(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, self::API_VALUES);

        $response->assertOk();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);
    }

    /**
     * 一般ユーザーは、公開API で他の人の勤怠を更新できないことを確かめる。
     * 一般ユーザーが Sanctum::actingAs() で他の人の勤怠（2026-06-01、09:00:00〜18:00:00）に PUT /api/v1/attendance-records/{id} で date 2026-06-01、clock_in 09:30:00、clock_out 18:00:00、comment「打刻の時刻を誤ったため」を送り、update() が持ち主でない一般ユーザーに許さず、403 で、勤怠の clock_in が 09:00:00、comment が空のままで、応答が {"error": "この操作を実行する権限がありません。"} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden_on_api_update(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($otherUser);
        Sanctum::actingAs($user);

        $response = $this->putJson('/api/v1/attendance-records/'.$attendanceRecord->id, self::API_VALUES);

        $response->assertForbidden();
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'comment' => null,
        ]);
        $response->assertExactJson(['error' => 'この操作を実行する権限がありません。']);
    }

    /**
     * 勤怠の持ち主は、公開API で自分の勤怠を消せることを確かめる。
     * 一般ユーザーが Sanctum::actingAs() で自分の勤怠（2026-06-01、09:00:00〜18:00:00）を DELETE /api/v1/attendance-records/{id} で消し、delete() が持ち主に許して、勤怠が消え、204 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_delete_via_api(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($user);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertNoContent();
        $this->assertModelMissing($attendanceRecord);
    }

    /**
     * 一般ユーザーは、公開API で他の人の勤怠を消せないことを確かめる。
     * 一般ユーザーが Sanctum::actingAs() で他の人の勤怠（2026-06-01、09:00:00〜18:00:00）を DELETE /api/v1/attendance-records/{id} で消し、delete() が持ち主でない一般ユーザーに許さず、403 で、勤怠が消えずに残り、応答が {"error": "この操作を実行する権限がありません。"} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden_on_api_delete(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createAttendanceRecord($otherUser);
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertForbidden();
        $this->assertModelExists($attendanceRecord);
        $response->assertExactJson(['error' => 'この操作を実行する権限がありません。']);
    }

    /**
     * その人の 2026-06-01 の休憩のない勤怠（09:00〜18:00）を作る。
     *
     * @param  User  $user  勤怠の持ち主の一般ユーザー
     * @return AttendanceRecord 作った勤怠
     */
    private function createAttendanceRecord(User $user): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }
}
