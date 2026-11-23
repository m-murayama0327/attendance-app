<?php

namespace Tests\Feature\App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $attendanceRecord = $this->createRecord($user);

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
     * 開く人が勤怠の持ち主なら、200 で user.user-detail の画面を出すことを確かめる。
     * 一般ユーザーが自分の勤怠（2026-06-01、09:00〜18:00）を GET /attendance/{id} で開き、view() が持ち主に見ることを許して、200 で user.user-detail を出すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_view(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = $this->createRecord($user);

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
        $attendanceRecord = $this->createRecord($otherUser);

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
        $attendanceRecord = $this->createRecord($user);

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
        $attendanceRecord = $this->createRecord($otherUser);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, self::DETAIL_VALUES);

        $response->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * その人の 2026-06-01 の休憩のない勤怠（09:00〜18:00）を作る。
     *
     * @param  User  $user  勤怠の持ち主の一般ユーザー
     * @return AttendanceRecord 作った勤怠
     */
    private function createRecord(User $user): AttendanceRecord
    {
        return AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }
}
