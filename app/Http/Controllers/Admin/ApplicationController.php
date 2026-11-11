<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    /**
     * 管理者の申請一覧の画面を出す。全ユーザーの申請を、申請日時の新しい順に渡す。承認待ちと承認済みのタブは、画面の側で分ける。
     *
     * @return View 管理者の申請一覧の画面
     */
    public function index(): View
    {
        return view('admin.admin-application-list', [
            'applications' => Application::allForAdminList(),
        ]);
    }
}
