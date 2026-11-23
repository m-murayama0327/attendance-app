<?php

namespace Tests\Feature\App\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserIsAdminTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人が一般ユーザーなら、403 が返ることを確かめる。
     * 一般ユーザーが POST /admin/logout を送り、handle() が管理者でない人を止めて、403 が返り、ログインしたままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_admin_logout(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/admin/logout');

        $response->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 開く人がログインしていない人なら、/admin/login に移ることを確かめる。
     * ログインしていない人が POST /admin/logout を送り、handle() がログインしていない人を止めて、/admin/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_admin_login_admin_logout(): void
    {
        $response = $this->post('/admin/logout');

        $response->assertRedirect('/admin/login');
    }

    /**
     * 開く人が管理者なら、止められずに 200 で開けることを確かめる。
     * 管理者が GET /admin/staff/list を送り、handle() が止めずに次の処理へ進めて、200 で admin.staff-list が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_passes_to_next(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/staff/list');

        $response->assertOk();
        $response->assertViewIs('admin.staff-list');
    }
}
