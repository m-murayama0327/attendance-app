<?php

namespace Tests\Feature\App\Http;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KernelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開いた番号の勤怠がなく、開く人が一般ユーザーなら、403 が返ることを確かめる。
     * 開いた番号の勤怠がないときに、一般ユーザーが GET /admin/attendance/999 を送り、$middlewarePriority が EnsureUserIsAdmin を勤怠を探す処理より先に動かして、403 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_for_missing_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/attendance/999');

        $response->assertForbidden();
    }

    /**
     * 開いた番号の勤怠がなく、開く人が管理者なら、403 が返ることを確かめる。
     * 開いた番号の勤怠がないときに、管理者が GET /attendance/detail/999 を送り、$middlewarePriority が EnsureUserIsGeneral を勤怠を探す処理より先に動かして、403 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_for_missing_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance/detail/999');

        $response->assertForbidden();
    }
}
