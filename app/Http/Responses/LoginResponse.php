<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    /**
     * ログインの後は、ログイン前に開いていたページに戻さず、管理者は勤怠一覧画面（管理者）へ、一般ユーザーは打刻画面へ移す。
     *
     * @param  Request  $request  ログインのリクエスト
     * @return RedirectResponse 役割に合った画面への転送
     */
    public function toResponse($request): RedirectResponse
    {
        if ($request->user()->admin_status) {
            return redirect('/admin/attendance/list');
        }

        return redirect('/attendance');
    }
}
