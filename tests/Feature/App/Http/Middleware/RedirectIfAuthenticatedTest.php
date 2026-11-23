<?php

namespace Tests\Feature\App\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RedirectIfAuthenticatedTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人が管理者なら、/admin/attendance/list に移ることを確かめる。
     * 管理者が GET /login を送り、handle() がログインしている人を homePath() の先に移して、/admin/attendance/list に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_redirects_from_login(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/login');

        $response->assertRedirect('/admin/attendance/list');
    }

    /**
     * 開く人が一般ユーザーなら、会員登録を送ってもユーザーが増えないことを確かめる。
     * 一般ユーザーが POST /register に正しい値を送り、handle() がログインしている人を homePath() の先に移して、/attendance に移り、ユーザーが増えず、hanako@example.com のユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_logged_in_user_cannot_register(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/register', [
            'name' => '山田花子',
            'email' => 'hanako@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseMissing('users', ['email' => 'hanako@example.com']);
    }

    /**
     * 開く人がログインしていない人なら、止められずに 200 で開けることを確かめる。
     * ログインしていない人が GET /login を送り、handle() が止めずに次の処理へ進めて、200 で user.user-login が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_passes_to_next(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertViewIs('user.user-login');
    }
}
