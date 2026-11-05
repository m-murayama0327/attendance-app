<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    /**
     * ログアウトの後は、管理者の入口から出た人は管理者のログイン画面へ、それ以外の人はログイン画面へ移す。
     *
     * @param  Request  $request  ログアウトのリクエスト
     * @return RedirectResponse 入口に合ったログイン画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        if ($request->is('admin/logout')) {
            return redirect('/admin/login');
        }

        return redirect('/login');
    }
}
