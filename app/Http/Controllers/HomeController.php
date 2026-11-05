<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    /**
     * トップページに来た人を、ログインしていなければログイン画面へ、ログインしていれば打刻画面へ移す。
     *
     * @return RedirectResponse ログインの状態に合った画面への転送
     */
    public function __invoke(): RedirectResponse
    {
        if (Auth::check()) {
            return redirect('/attendance');
        }

        return redirect('/login');
    }
}
