<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * ログインの後は、ログイン前に開いていたページに戻さず、打刻画面へ移す。
     *
     * @param  Request  $request  ログインのリクエスト
     * @return RedirectResponse 打刻画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        return redirect('/attendance');
    }
}
