<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\View\View;

class StaffController extends Controller
{
    /**
     * スタッフ一覧を出す。一般ユーザー全員を登録順に渡す。
     *
     * @return View スタッフ一覧の画面
     */
    public function index(): View
    {
        return view('admin.staff-list', [
            'users' => User::general()->orderBy('id')->get(),
        ]);
    }
}
