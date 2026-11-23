<?php

namespace Tests\Feature\App\Http\Responses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ログイン前に開こうとした URL（url.intended）があっても、/attendance に移ることを確かめる。
     * url.intended が /attendance/list のときに、一般ユーザーが POST /login に登録どおりの値を送り、toResponse() が url.intended を使わずに、/attendance に移り、その一般ユーザーでログインした状態になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_login_ignores_intended_url(): void
    {
        $user = User::factory()->create(['email' => 'test@example.com', 'admin_status' => false]);
        $this->get('/attendance/list')
            ->assertRedirect('/login')
            ->assertSessionHas('url.intended', url('/attendance/list'));

        $response = $this->post('/login', ['email' => 'test@example.com', 'password' => 'password']);

        $response->assertRedirect('/attendance');
        $this->assertAuthenticatedAs($user);
    }
}
