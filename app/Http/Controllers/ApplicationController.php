<?php

namespace App\Http\Controllers;

use App\Models\Application;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApplicationController extends Controller
{
    /**
     * 申請一覧の画面を出す。ログインしている人の申請の行を、申請日時の新しい順に渡す。承認待ちと承認済みのタブは、画面の側で分ける。
     *
     * @param  Request  $request  届いたリクエスト
     * @return View 申請一覧の画面
     */
    public function index(Request $request): View
    {
        return view('user.user-application-list', [
            'formattedApplications' => Application::listRowsFor($request->user()),
            'user' => $request->user(),
        ]);
    }
}
