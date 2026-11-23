<?php

namespace Tests\Feature\App\Policies;

use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplicationPolicyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人が申請した人でない管理者でも、view() が true を返すことを確かめる。
     * テストから管理者と一般ユーザーの承認待ちの申請（2026-06-01）で view() を呼び、view() が管理者に見ることを許して true を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_policy_allows_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = $this->createPendingApplication($user);

        $this->assertTrue((new ApplicationPolicy)->view($admin, $application));
    }

    /**
     * 開く人が申請した一般ユーザーなら、200 で user.user-detail の画面を出すことを確かめる。
     * 一般ユーザーが自分の承認待ちの申請（2026-06-01）を GET /application/{id} で開き、view() が申請した人に見ることを許して、200 で user.user-detail を出すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_owner_can_view(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = $this->createPendingApplication($user);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
    }

    /**
     * 開く人が他の一般ユーザーなら、403 になることを確かめる。
     * 一般ユーザーが他の人の承認待ちの申請（2026-06-01）を GET /application/{id} で開き、view() が申請した人でない一般ユーザーに見ることを許さず、403 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $application = $this->createPendingApplication($otherUser);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertForbidden();
    }

    /**
     * その人の 2026-06-01 の勤怠（09:00〜18:00）と、出勤と退勤の時刻を直す承認待ちの申請を作る。
     *
     * @param  User  $user  勤怠と申請の持ち主の一般ユーザー
     * @return Application 作った申請
     */
    private function createPendingApplication(User $user): Application
    {
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        return Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);
    }
}
