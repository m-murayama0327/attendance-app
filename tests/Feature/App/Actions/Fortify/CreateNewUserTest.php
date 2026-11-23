<?php

namespace Tests\Feature\App\Actions\Fortify;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateNewUserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 名前 山田太郎、メール yamada@example.com、パスワード password を送ると、ユーザーが1行でき、名前とメールが送った値になることを確かめる。
     * ログインしていない人が POST /register に送り、create() が確かめた値でユーザーを作って、パスワードがハッシュ化された一般ユーザーでログインし、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_is_created(): void
    {
        $response = $this->post('/register', [
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
        $user = User::where('email', 'yamada@example.com')->first();
        $this->assertSame('山田太郎', $user->name);
        $this->assertNotSame('password', $user->password);
        $this->assertTrue(Hash::check('password', $user->password));
        $this->assertFalse($user->admin_status);
        $this->assertAuthenticatedAs($user);
    }
}
