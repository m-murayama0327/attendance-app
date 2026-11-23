<?php

namespace Tests\Feature\Config;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class FortifyTest extends TestCase
{
    use RefreshDatabase;

    /**
     * email を配列 ['yamada@example.com'] にして POST /register を送ると、email に「メールアドレスはメール形式で入力してください」が出ることを確かめる。
     * ログインしていない人が email を配列にして POST /register を送り、lowercase_usernames が false で Fortify が小文字にせずに入力の確かめへ進めて、/register へ戻り、email にエラーが出て、users が0行かを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_array_is_rejected(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => '山田太郎',
            'email' => ['yamada@example.com'],
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $response->assertRedirect('/register');
        $response->assertSessionHasErrors(['email' => 'メールアドレスはメール形式で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * パスワードの再設定のパス /forgot-password を開くと、404 になることを確かめる。
     * ログインしていない人が GET /forgot-password を送り、features が Features::resetPasswords() を外してパスワードの再設定のルートを置かず、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_reset_page_is_not_found(): void
    {
        $response = $this->get('/forgot-password');

        $response->assertNotFound();
    }

    /**
     * 名前とメールアドレスを変えて PUT /user/profile-information を送ると、404 になって name と email が変わらないことを確かめる。
     * 一般ユーザーが name と email を変えて PUT /user/profile-information を送り、features が Features::updateProfileInformation() を外してルートを置かず、404 になって2つの値が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_profile_update_is_not_found(): void
    {
        $user = User::factory()->create(['name' => '山田太郎', 'email' => 'yamada@example.com', 'admin_status' => false]);

        $response = $this->actingAs($user)->put('/user/profile-information', [
            'name' => '山田花子',
            'email' => 'hanako@example.com',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => '山田太郎', 'email' => 'yamada@example.com']);
    }

    /**
     * パスワードを newpassword に変えて PUT /user/password を送ると、404 になってパスワードが password のままであることを確かめる。
     * パスワードが password のときに、一般ユーザーが新しいパスワードで PUT /user/password を送り、features が Features::updatePasswords() を外してルートを置かず、404 になってパスワードが password のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_update_is_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->put('/user/password', [
            'current_password' => 'password',
            'password' => 'newpassword',
            'password_confirmation' => 'newpassword',
        ]);

        $response->assertNotFound();
        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    /**
     * POST /user/two-factor-authentication を送ると、404 になることを確かめる。
     * 一般ユーザーが POST /user/two-factor-authentication を送り、features が Features::twoFactorAuthentication() を外してルートを置かず、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_two_factor_enable_is_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/user/two-factor-authentication');

        $response->assertNotFound();
    }
}
