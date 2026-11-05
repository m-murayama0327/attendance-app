<?php

namespace App\Http\Controllers;

use App\Http\Requests\StampRequest;
use App\Models\AttendanceRecord;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AttendanceController extends Controller
{
    /**
     * 打刻の画面を出す。今の日付と時刻を表示用の文字にして、ログインしているユーザーと一緒に渡す。
     *
     * @param  Request  $request  届いたリクエスト
     * @return View 打刻の画面
     */
    public function index(Request $request): View
    {
        return view('user.attendance-register', [
            'user' => $request->user(),
            'formattedDate' => now()->isoFormat('YYYY年M月D日(ddd)'),
            'formattedTime' => now()->format('H:i'),
        ]);
    }

    /**
     * 押したボタンが今の状態でできる打刻なら、時刻を書く。できない打刻なら、何も書かない。どちらも打刻の画面に戻す。
     *
     * @param  StampRequest  $request  押したボタン（action）を確かめたリクエスト
     * @return RedirectResponse 打刻の画面への転送
     */
    public function store(StampRequest $request): RedirectResponse
    {
        $user = $request->user();
        $action = $request->validated('action');

        DB::transaction(function () use ($user, $action) {
            $status = $user->attendance_status;
            $record = $user->findTodayAttendanceRecord();

            if ($action === 'clock_in' && $status === '勤務外') {
                AttendanceRecord::clockIn($user);
            } elseif ($action === 'clock_out' && $status === '出勤中') {
                $record->clockOut();
            } elseif ($action === 'break_in' && $status === '出勤中') {
                $record->breakIn();
            } elseif ($action === 'break_out' && $status === '休憩中') {
                $record->breakOut();
            }
        });

        return redirect('/attendance');
    }
}
