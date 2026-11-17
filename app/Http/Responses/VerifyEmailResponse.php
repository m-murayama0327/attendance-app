<?php

namespace App\Http\Responses;

use App\Providers\RouteServiceProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\VerifyEmailResponse as VerifyEmailResponseContract;

class VerifyEmailResponse implements VerifyEmailResponseContract
{
    /**
     * メール認証を済ませた後は、認証の前に開こうとしていた画面に戻さず、勤怠登録画面へ移す。
     *
     * @param  Request  $request  認証のリンクを開いたリクエスト
     * @return RedirectResponse 勤怠登録画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        return redirect(RouteServiceProvider::HOME);
    }
}
