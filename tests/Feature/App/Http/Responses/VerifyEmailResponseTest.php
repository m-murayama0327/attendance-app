<?php

namespace Tests\Feature\App\Http\Responses;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class VerifyEmailResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 認証の前に開こうとした画面がないときは、認証メールのリンクを開いた後に勤怠登録画面に移ることを確かめる。
     * 未認証の一般ユーザーが認証メールの署名つきのリンク（GET /email/verify/{id}/{hash}）を開き、toResponse() が勤怠登録画面へ移して、/attendance に移り、ユーザーの email_verified_at に日時が入るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_verification_redirects_to_attendance(): void
    {
        $user = User::factory()->unverified()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get($this->verificationUrl($user));

        $response->assertRedirect('/attendance');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * 認証の前に他の画面を開こうとしていても、認証メールのリンクを開いた後は勤怠登録画面に移ることを確かめる。
     * 未認証の一般ユーザーが GET /attendance/list を開いて /email/verify に移った後に、認証メールの署名つきのリンク（GET /email/verify/{id}/{hash}）を開き、toResponse() が url.intended を使わずに勤怠登録画面へ移して、/attendance に移り、ユーザーの email_verified_at に日時が入るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_verification_ignores_intended_url(): void
    {
        $user = User::factory()->unverified()->create(['admin_status' => false]);
        $this->actingAs($user)->get('/attendance/list');

        $response = $this->actingAs($user)->get($this->verificationUrl($user));

        $response->assertRedirect('/attendance');
        $this->assertNotNull($user->fresh()->email_verified_at);
    }

    /**
     * そのユーザーの認証メールの署名つきのリンク（60分で切れる）を作る。
     *
     * @param  User  $user  認証するユーザー
     * @return string 認証のリンクの URL
     */
    private function verificationUrl(User $user): string
    {
        return URL::temporarySignedRoute('verification.verify', now()->addMinutes(60), [
            'id' => $user->id,
            'hash' => sha1($user->getEmailForVerification()),
        ]);
    }
}
