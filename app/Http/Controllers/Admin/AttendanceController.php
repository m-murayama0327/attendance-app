<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /**
     * 管理者の勤怠一覧を出す。?date= の日（ないときと読めないときは今日）について、その日の勤怠と行にする人を渡す。行にする人は、一般ユーザー全員とその日に勤怠がある管理者にする。
     *
     * @param  Request  $request  届いたリクエスト
     * @return View 管理者の勤怠一覧の画面
     */
    public function index(Request $request): View
    {
        $date = today();
        $dateValue = $request->query('date');

        if (is_string($dateValue)) {
            try {
                $date = Carbon::parse($dateValue)->startOfDay();
            } catch (InvalidFormatException) {
                // 読めない値はエラーを出す画面がないので、今日のままにする
            }
        }

        $attendanceRecords = AttendanceRecord::where('date', $date->toDateString())->get();

        $users = User::where('admin_status', false)
            ->orWhereIn('id', $attendanceRecords->pluck('user_id'))
            ->orderBy('id')
            ->get();

        return view('admin.admin-attendance-list', [
            'date' => $date,
            'previousDay' => $date->copy()->subDay()->toDateString(),
            'nextDay' => $date->copy()->addDay()->toDateString(),
            'users' => $users,
            'attendanceRecords' => $attendanceRecords,
        ]);
    }
}
