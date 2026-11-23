<?php

namespace Tests\Feature\App\Http\Responses;

use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class EmailVerificationNotificationSentResponseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * メール認証誘導画面から認証メールを再送すると、メール認証誘導画面に移って再送を知らせる文がセッションに入ることを確かめる。
     * 未認証の一般ユーザーが GET /email/verify を開いた後に POST /email/verification-notification を送り、toResponse() が前に開いた画面へ戻して message に文を入れ、/email/verify に移り、セッションの message が「認証メールを再送しました」で、そのユーザーに VerifyEmail の通知が送られるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_resend_returns_to_verify_page_with_message(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create(['admin_status' => false]);
        $this->actingAs($user)->get('/email/verify');

        $response = $this->actingAs($user)->post('/email/verification-notification');

        $response->assertRedirect('/email/verify');
        $response->assertSessionHas('message', '認証メールを再送しました');
        Notification::assertSentTo($user, VerifyEmail::class);
    }
}
