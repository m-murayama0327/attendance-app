<?php

namespace Tests\Feature\App\Http\Responses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 送る先の URL が /logout なら、/login に移ることを確かめる。
     * 一般ユーザーが POST /logout を送り、toResponse() が送る先の URL で移る先を決めて、/login に移り、ログインしていない状態になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_redirects_to_login(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/logout');

        $response->assertRedirect('/login');
        $this->assertGuest();
    }

    /**
     * 送る先の URL が /admin/logout なら、/admin/login に移ることを確かめる。
     * 管理者が POST /admin/logout を送り、toResponse() が送る先の URL で移る先を決めて、/admin/login に移り、ログインしていない状態になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_redirects_to_admin_login(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->post('/admin/logout');

        $response->assertRedirect('/admin/login');
        $this->assertGuest();
    }
}
