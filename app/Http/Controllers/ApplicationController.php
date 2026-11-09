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

    /**
     * 申請詳細の画面を出す。申請した人でなければ 403 にする。申請がなければ、ルートモデルバインディングが 404 にする。
     * 承認待ちでも承認済みでも、押した申請の値を読み取り専用の形で出す。
     *
     * @param  Application  $application  URL の id で読んだ申請
     * @return View 申請詳細の画面
     */
    public function show(Application $application): View
    {
        $this->authorize('view', $application);

        return view('user.user-detail', [
            'data' => $application->toDetailData(),
            'user' => $application->AttendanceRecord->user,
        ]);
    }
}
