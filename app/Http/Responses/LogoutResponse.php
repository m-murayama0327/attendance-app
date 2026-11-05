<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * ログアウトの後は、ログイン画面へ移す。
     *
     * @param  Request  $request  ログアウトのリクエスト
     * @return RedirectResponse ログイン画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        return redirect('/login');
    }
}
