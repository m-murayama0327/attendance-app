<?php

namespace Tests\Feature\App\Providers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class FortifyServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 同じ IP から誤ったパスワードで4回送った後の5回目は /login へ戻り、6回目は 429 になることを確かめる。
     * 一般ユーザーと管理者がいるときに、test@example.com と wrongpass で POST /login を同じ IP から6回送り、boot() の login の回数の制限が数えて、5回目が /login へ戻り、6回目が 429 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_login_is_rate_limited(): void
    {
        $this->createUsers();
        Collection::times(4, fn (): TestResponse => $this->from('/login')->post('/login', [
            'email' => 'test@example.com',
            'password' => 'wrongpass',
        ]));

        $fifthResponse = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => 'wrongpass']);
        $sixthResponse = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => 'wrongpass']);

        $fifthResponse->assertRedirect('/login');
        $sixthResponse->assertStatus(429);
    }

    /**
     * POST /login に3回、POST /admin/login に2回送った後なら、6回目の POST /admin/login が 429 になることを確かめる。
     * 一般ユーザーと管理者がいるときに、admin@example.com と wrongpass で同じ IP から2つのログインに5回送り、boot() の login の回数の制限が合わせて数えて、6回目が 429 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_rate_limit_is_shared_with_login(): void
    {
        $this->createUsers();
        Collection::times(3, fn (): TestResponse => $this->from('/login')->post('/login', [
            'email' => 'admin@example.com',
            'password' => 'wrongpass',
        ]));
        Collection::times(2, fn (): TestResponse => $this->from('/admin/login')->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'wrongpass',
        ]));

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrongpass']);

        $response->assertStatus(429);
    }

    /**
     * 一般ユーザー（test@example.com）が /login に送ると、そのユーザーでログインした状態になることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /login に test@example.com と password を送り、boot() の照合がそのユーザーを返して、ログインした状態で /attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_can_login(): void
    {
        $this->createUsers();

        $response = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs(User::where('email', 'test@example.com')->sole());
        $response->assertRedirect('/attendance');
    }

    /**
     * 管理者（admin@example.com）が /admin/login に送ると、その管理者でログインした状態になることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /admin/login に admin@example.com と password を送り、boot() の照合がその管理者を返して、ログインした状態で /admin/attendance/list に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_can_login(): void
    {
        $this->createUsers();

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password']);

        $this->assertAuthenticatedAs(User::where('email', 'admin@example.com')->sole());
        $response->assertRedirect('/admin/attendance/list');
    }

    /**
     * 一般ユーザー（test@example.com）が /admin/login に送ると、email に「ログイン情報が登録されていません」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /admin/login に test@example.com と password を送り、boot() の照合が null を返して、email にエラーが出て、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_cannot_login_from_admin_login(): void
    {
        $this->createUsers();

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => 'test@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->assertGuest();
    }

    /**
     * 管理者（admin@example.com）が /login に送ると、email に「ログイン情報が登録されていません」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /login に admin@example.com と password を送り、boot() の照合が null を返して、email にエラーが出て、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_cannot_login_from_login(): void
    {
        $this->createUsers();

        $response = $this->from('/login')->post('/login', ['email' => 'admin@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->assertGuest();
    }

    /**
     * /register を開くと、入力4項目と /login へのリンクが出ることを確かめる。
     * ログインしていない人が GET /register を開き、boot() が会員登録の画面を決めて、200 で user.register の入力4項目と /login へのリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_register_page_is_shown(): void
    {
        $response = $this->get('/register');

        $response->assertOk();
        $response->assertViewIs('user.register');
        $response->assertSee('name="name"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('name="password_confirmation"', false);
        $response->assertSee('href="/login"', false);
    }

    /**
     * /login を開くと、入力2項目と /register へのリンクが出ることを確かめる。
     * ログインしていない人が GET /login を開き、boot() がログインの画面を決めて、200 で user.user-login の入力2項目と /register へのリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_login_page_is_shown(): void
    {
        $response = $this->get('/login');

        $response->assertOk();
        $response->assertViewIs('user.user-login');
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
        $response->assertSee('href="/register"', false);
    }

    /**
     * /admin/login を開くと、入力2項目と送り先が /admin/login のフォームが出ることを確かめる。
     * ログインしていない人が GET /admin/login を開き、boot() がログインの画面を決めて、200 で admin.admin-login の入力2項目と送り先が /admin/login のフォームが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_login_page_is_shown(): void
    {
        $response = $this->get('/admin/login');

        $response->assertOk();
        $response->assertViewIs('admin.admin-login');
        $response->assertSee('action="/admin/login"', false);
        $response->assertSee('name="email"', false);
        $response->assertSee('name="password"', false);
    }

    /**
     * メールが wrong@example.com なら、email に「ログイン情報が登録されていません」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /login に wrong@example.com と password を送り、boot() の照合が null を返して、email にエラーが出て、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_wrong_email_shows_error_login(): void
    {
        $this->createUsers();

        $response = $this->from('/login')->post('/login', ['email' => 'wrong@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->assertGuest();
    }

    /**
     * メールが wrong@example.com で送る先が /admin/login なら、email に「ログイン情報が登録されていません」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /admin/login に wrong@example.com と password を送り、boot() の照合が null を返して、email にエラーが出て、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_wrong_email_shows_error_admin_login(): void
    {
        $this->createUsers();

        $response = $this->from('/admin/login')->post('/admin/login', ['email' => 'wrong@example.com', 'password' => 'password']);

        $response->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->assertGuest();
    }

    /**
     * メールが配列 ['test@example.com'] なら、/login へ戻り、email に「メールアドレスはメール形式で入力してください」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /login に配列のメールと password を送り、boot() の login の回数の制限が文字列でないメールを空にして、/login へ戻り、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_array_is_rejected(): void
    {
        $this->createUsers();

        $response = $this->from('/login')->post('/login', ['email' => ['test@example.com'], 'password' => 'password']);

        $response->assertRedirect('/login');
        $response->assertSessionHasErrors(['email' => 'メールアドレスはメール形式で入力してください']);
        $this->assertGuest();
    }

    /**
     * パスワードが wrongpass なら、email に「ログイン情報が登録されていません」が出ることを確かめる。
     * 一般ユーザーと管理者がいるときに、POST /login に test@example.com と wrongpass を送り、boot() の照合が null を返して、email にエラーが出て、ログインしていないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_wrong_password_shows_error(): void
    {
        $this->createUsers();

        $response = $this->from('/login')->post('/login', ['email' => 'test@example.com', 'password' => 'wrongpass']);

        $response->assertSessionHasErrors(['email' => 'ログイン情報が登録されていません']);
        $this->assertGuest();
    }

    /**
     * ログインの行で使う、一般ユーザー（test@example.com）と管理者（admin@example.com）をパスワード password で登録する。
     *
     * @return void 返す値はない
     */
    private function createUsers(): void
    {
        User::factory()->create(['email' => 'test@example.com', 'admin_status' => false]);
        User::factory()->create(['email' => 'admin@example.com', 'admin_status' => true]);
    }
}
