<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Application;
use Illuminate\Http\RedirectResponse;
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

    /**
     * 修正申請承認画面を出す。申請と申請した人を渡す。
     *
     * @param  Application  $application  URL の番号で読んだ申請
     * @return View 修正申請承認画面
     */
    public function show(Application $application): View
    {
        return view('admin.admin-application-detail', [
            'application' => $application,
            'user' => $application->user,
        ]);
    }

    /**
     * 修正申請を承認し、同じ画面へ戻す。承認済みの申請に送られたときは、データを変えずに同じ画面へ戻す。
     *
     * @param  Application  $application  URL の番号で読んだ申請
     * @return RedirectResponse 修正申請承認画面への転送
     */
    public function approve(Application $application): RedirectResponse
    {
        if (! $application->is_approved) {
            $application->approve();
        }

        return redirect('/stamp_correction_request/approve/'.$application->id);
    }
}
