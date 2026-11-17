<?php

namespace App\Http\Controllers;

use App\Services\AttendanceReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ReportController extends Controller
{
    /**
     * ログインしている一般ユーザーの当月を含む6か月の集計を作り、マイ勤怠レポートの画面を出す。
     *
     * @param  Request  $request  届いたリクエスト（ログインしている一般ユーザーを持つ）
     * @param  AttendanceReportService  $attendanceReportService  集計を作るクラス
     * @return View マイ勤怠レポートの画面
     */
    public function index(Request $request, AttendanceReportService $attendanceReportService): View
    {
        return view('reports.index', $attendanceReportService->build($request->user()));
    }
}
