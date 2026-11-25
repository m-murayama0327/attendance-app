<?php

namespace Tests\Feature\App\Http\Controllers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomeControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人がログインしている一般ユーザーなら、打刻画面に移ることを確かめる。
     * ログインしている一般ユーザーが GET / を送り、__invoke() が homePath() の移り先を選んで、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_general_user_redirects_to_stamp_page(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect('/attendance');
    }

    /**
     * 開く人がログインしている管理者なら、勤怠一覧画面（管理者）に移ることを確かめる。
     * ログインしている管理者が GET / を送り、__invoke() が homePath() の移り先を選んで、/admin/attendance/list に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_redirects_to_admin_attendance_list(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/');

        $response->assertRedirect('/admin/attendance/list');
    }

    /**
     * 開く人がログインしていない人なら、/login に移ることを確かめる。
     * ログインしていない人が GET / を送り、__invoke() がログインしているかで移る先を決めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }
}
