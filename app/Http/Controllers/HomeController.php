<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * トップページに来た人を、ログインしていなければログイン画面へ、管理者は勤怠一覧画面（管理者）へ、一般ユーザーは打刻画面へ移す。
     *
     * @return RedirectResponse ログインの状態と役割に合った画面への転送
     */
    public function __invoke(): RedirectResponse
    {
        $user = Auth::user();

        if ($user === null) {
            return redirect('/login');
        }

        if ($user->admin_status) {
            return redirect('/admin/attendance/list');
        }

        return redirect('/attendance');
    }
}
