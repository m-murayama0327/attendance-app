<?php

namespace Tests\Feature\App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * email が空なら、email に「メールアドレスを入力してください」が出ることを確かめる。
     * 一般ユーザーがいるときに、ログインしていない人が POST /login で email を空にして送り、rules() が email の required で止めて、「メールアドレスを入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_is_required_login(): void
    {
        $this->createGeneralUser();

        $response = $this->from('/login')->post('/login', ['email' => '', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
        $this->assertGuest();
    }

    /**
     * 送る先が /admin/login で email が空でも、email に「メールアドレスを入力してください」が出ることを確かめる。
     * 管理者がいるときに、ログインしていない人が POST /admin/login で email を空にして送り、rules() が email の required で止めて、「メールアドレスを入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_is_required_admin_login(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'admin_status' => true]);

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => '', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
        $this->assertGuest();
    }

    /**
     * email が abc なら、email に「メールアドレスはメール形式で入力してください」が出ることを確かめる。
     * 一般ユーザーがいるときに、ログインしていない人が POST /login で email を abc にして送り、rules() が email の email で止めて、「メールアドレスはメール形式で入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_format(): void
    {
        $this->createGeneralUser();

        $response = $this->from('/login')->post('/login', ['email' => 'abc', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスはメール形式で入力してください']);
        $this->assertGuest();
    }

    /**
     * password が空なら、password に「パスワードを入力してください」が出ることを確かめる。
     * 一般ユーザーがいるときに、ログインしていない人が POST /login で password を空にして送り、rules() が password の required で止めて、「パスワードを入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_is_required_login(): void
    {
        $this->createGeneralUser();

        $response = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => '']);

        $response->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
        $this->assertGuest();
    }

    /**
     * 送る先が /admin/login で password が空でも、password に「パスワードを入力してください」が出ることを確かめる。
     * 管理者がいるときに、ログインしていない人が POST /admin/login で password を空にして送り、rules() が password の required で止めて、「パスワードを入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_is_required_admin_login(): void
    {
        User::factory()->create(['email' => 'admin@example.com', 'admin_status' => true]);

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@example.com', 'password' => '']);

        $response->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
        $this->assertGuest();
    }

    /**
     * password が配列 ['password'] なら、password に「パスワードは文字で入力してください」が出ることを確かめる。
     * 一般ユーザーがいるときに、ログインしていない人が POST /login で password を配列 ['password'] にして送り、rules() が password の string で止めて、「パスワードは文字で入力してください」が出て、ログインしていないままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_must_be_string(): void
    {
        $this->createGeneralUser();

        $response = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => ['password']]);

        $response->assertSessionHasErrors(['password' => 'パスワードは文字で入力してください']);
        $this->assertGuest();
    }

    /**
     * ログインに使う一般ユーザー（test@example.com、パスワード password）を登録する。
     *
     * @return void 返す値はない
     */
    private function createGeneralUser(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'admin_status' => false]);
    }
}
