<?php

namespace Tests\Feature\App\Http\Middleware;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnsureUserIsGeneralTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人が管理者なら、403 が返ることを確かめる。
     * 管理者が GET /attendance を送り、handle() が一般ユーザーでない人を止めて、403 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_attendance_page(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance');

        $response->assertForbidden();
    }

    /**
     * 開く人が一般ユーザーなら、止められずに 200 で開けることを確かめる。
     * 一般ユーザーが GET /attendance を送り、handle() が止めずに次の処理へ進めて、200 で user.attendance-register が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_passes_to_next(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertViewIs('user.attendance-register');
    }
}
