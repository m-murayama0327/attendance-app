<?php

namespace Tests\Feature\App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class RegisterRequestTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 送る email と同じ email のユーザーがあるなら、email に「このメールアドレスは既に登録されています」が出ることを確かめる。
     * yamada@example.com のユーザーがあるときに、ログインしていない人が POST /register で同じ email を送り、rules() が email の unique:users で止めて、「このメールアドレスは既に登録されています」が出て、ユーザーが増えないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'yamada@example.com']);

        $response = $this->postRegister([]);

        $response->assertSessionHasErrors(['email' => 'このメールアドレスは既に登録されています']);
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * admin_status に 1 を足して送っても、admin_status が false のユーザーができることを確かめる。
     * ログインしていない人が POST /register で admin_status=1 を足して送り、rules() が admin_status を確かめた値に入れずに、/attendance に移り、email が yamada@example.com で admin_status が false のユーザーが1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_status_cannot_be_sent(): void
    {
        $response = $this->postRegister(['admin_status' => 1]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('users', [
            'email' => 'yamada@example.com',
            'admin_status' => false,
        ]);
    }

    /**
     * name が255文字なら、エラーにならず、ユーザーが1行できることを確かめる。
     * ログインしていない人が POST /register で name を255文字にして送り、rules() が name の max:255 で通して、ユーザーが1行でき、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_name_of_max_length_is_accepted(): void
    {
        $response = $this->postRegister(['name' => str_repeat('あ', 255)]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * password と password_confirmation が8文字（abcdefgh）なら、エラーにならず、ユーザーが1行できることを確かめる。
     * ログインしていない人が POST /register で password と password_confirmation を8文字にして送り、rules() が password の min:8 で通して、ユーザーが1行でき、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_of_min_length_is_accepted(): void
    {
        $response = $this->postRegister(['password' => 'abcdefgh', 'password_confirmation' => 'abcdefgh']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * email が @ の前を伸ばした255文字なら、エラーにならず、ユーザーが1行できることを確かめる。
     * ログインしていない人が POST /register で email を255文字にして送り、rules() が email の max:255 で通して、ユーザーが1行でき、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_of_max_length_is_accepted(): void
    {
        $response = $this->postRegister(['email' => str_repeat('a', 243).'@example.com']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('users', 1);
    }

    /**
     * name が空なら、name に「お名前を入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で name を空にして送り、rules() が name の required で止めて、「お名前を入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_name_is_required(): void
    {
        $response = $this->postRegister(['name' => '']);

        $response->assertSessionHasErrors(['name' => 'お名前を入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * name が256文字なら、name に「お名前は255文字以内で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で name を256文字にして送り、rules() が name の max:255 で止めて、「お名前は255文字以内で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_name_max_length(): void
    {
        $response = $this->postRegister(['name' => str_repeat('あ', 256)]);

        $response->assertSessionHasErrors(['name' => 'お名前は255文字以内で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * name が配列 ['山田太郎'] なら、name に「お名前は文字で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で name を配列 ['山田太郎'] にして送り、rules() が name の string で止めて、「お名前は文字で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_name_must_be_string(): void
    {
        $response = $this->postRegister(['name' => ['山田太郎']]);

        $response->assertSessionHasErrors(['name' => 'お名前は文字で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * email が空なら、email に「メールアドレスを入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で email を空にして送り、rules() が email の required で止めて、「メールアドレスを入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_is_required(): void
    {
        $response = $this->postRegister(['email' => '']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスを入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * email が abc なら、email に「メールアドレスはメール形式で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で email を abc にして送り、rules() が email の email で止めて、「メールアドレスはメール形式で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_format(): void
    {
        $response = $this->postRegister(['email' => 'abc']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスはメール形式で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * email が @ の前を伸ばした256文字なら、email に「メールアドレスは255文字以内で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で email を256文字にして送り、rules() が email の max:255 で止めて、「メールアドレスは255文字以内で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_email_max_length(): void
    {
        $response = $this->postRegister(['email' => str_repeat('a', 244).'@example.com']);

        $response->assertSessionHasErrors(['email' => 'メールアドレスは255文字以内で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * password が空なら、password に「パスワードを入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で password を空にして送り、rules() が password の required で止めて、「パスワードを入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_is_required(): void
    {
        $response = $this->postRegister(['password' => '']);

        $response->assertSessionHasErrors(['password' => 'パスワードを入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * password と password_confirmation が7文字（abcdefg）なら、password に「パスワードは8文字以上で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で password と password_confirmation を7文字にして送り、rules() が password の min:8 で止めて、「パスワードは8文字以上で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_min_length(): void
    {
        $response = $this->postRegister(['password' => 'abcdefg', 'password_confirmation' => 'abcdefg']);

        $response->assertSessionHasErrors(['password' => 'パスワードは8文字以上で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * password が配列 ['password'] なら、password に「パスワードは文字で入力してください」が出ることを確かめる。
     * ログインしていない人が POST /register で password を配列 ['password'] にして送り、rules() が password の string で止めて、「パスワードは文字で入力してください」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_must_be_string(): void
    {
        $response = $this->postRegister(['password' => ['password']]);

        $response->assertSessionHasErrors(['password' => 'パスワードは文字で入力してください']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * password_confirmation を送らないなら、password_confirmation に「パスワードと一致しません」が出ることを確かめる。
     * ログインしていない人が POST /register に password_confirmation を除いて送り、rules() が password_confirmation の required で止めて、「パスワードと一致しません」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_confirmation_must_be_sent(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'password' => 'password',
        ]);

        $response->assertSessionHasErrors(['password_confirmation' => 'パスワードと一致しません']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * password_confirmation が password1 なら、password_confirmation に「パスワードと一致しません」が出ることを確かめる。
     * ログインしていない人が POST /register で password_confirmation を password1 にして送り、rules() が password_confirmation の same:password で止めて、「パスワードと一致しません」が出て、ユーザーができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_password_confirmation_must_match(): void
    {
        $response = $this->postRegister(['password_confirmation' => 'password1']);

        $response->assertSessionHasErrors(['password_confirmation' => 'パスワードと一致しません']);
        $this->assertDatabaseCount('users', 0);
    }

    /**
     * ログインしていない人として、会員登録の画面から登録の値を送る。条件に書いていない項目は、正しい値を送る。
     *
     * @param  array<string, mixed>  $overrides  正しい値から変える項目と値
     * @return TestResponse 送った後の応答
     */
    private function postRegister(array $overrides): TestResponse
    {
        return $this->from('/register')->post('/register', array_merge([
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ], $overrides));
    }
}
