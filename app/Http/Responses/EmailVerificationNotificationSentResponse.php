<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\EmailVerificationNotificationSentResponse as EmailVerificationNotificationSentResponseContract;

class EmailVerificationNotificationSentResponse implements EmailVerificationNotificationSentResponseContract
{
    /**
     * 認証メールを再送した後は、元の画面（メール認証誘導画面）へ戻し、再送できたことを知らせる文を出す。
     * Fortify の既定は文を `status` の名前でセッションに入れるが、提供された Blade は `message` を読むため、`message` に入れる。
     *
     * @param  Request  $request  再送のリクエスト
     * @return RedirectResponse 元の画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        return back()->with('message', '認証メールを再送しました');
    }
}
