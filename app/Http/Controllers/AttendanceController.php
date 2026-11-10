<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceDetailRequest;
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
     * 勤怠一覧の画面を出す。?date= の値から表示する月を決め、その月の全部の日の行を渡す。
     *
     * @param  Request  $request  届いたリクエスト（?date= に表示する月）
     * @return View 勤怠一覧の画面
     */
    public function list(Request $request): View
    {
        $monthView = AttendanceRecord::resolveMonthView($request->query('date'));

        return view('user.user-attendance-list', [
            'date' => $monthView['date'],
            'previousMonth' => $monthView['previousMonth'],
            'nextMonth' => $monthView['nextMonth'],
            'formattedAttendanceRecords' => AttendanceRecord::monthlyRows($request->user(), $monthView['date']),
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

    /**
     * 一般ユーザーの勤怠詳細の画面を出す。他の人の勤怠なら 403 にする。勤怠がなければ、ルートモデルバインディングが 404 にする。
     * 承認待ちの申請がなく、入力の誤りで戻ったときは、送った値で時刻、休憩、備考を置き換える。承認待ちの申請があるときは読み取り専用で出すので、送った値は使わない。
     *
     * @param  Request  $request  届いたリクエスト（エラーで戻ったときは送った値を持つ）
     * @param  AttendanceRecord  $attendanceRecord  URL の id で読んだ勤怠
     * @return View 勤怠詳細の画面
     */
    public function show(Request $request, AttendanceRecord $attendanceRecord): View
    {
        $this->authorize('view', $attendanceRecord);

        $application = $attendanceRecord->pendingApplication();
        $detail = $attendanceRecord->toDetailData($application === null ? $request->session()->getOldInput() : []);
        $detail['application'] = $application;

        return view('user.user-detail', [
            'data' => $detail,
            'user' => $attendanceRecord->user,
        ]);
    }

    /**
     * 修正申請を作り、勤怠詳細の画面へ戻す。勤怠の値は変えず、承認のときに変える。
     * 承認待ちの申請がある勤怠には、画面に修正のボタンが出なくても送られうるので、ここでも申請を作らない。
     *
     * @param  AttendanceDetailRequest  $request  勤怠を直してよい人と入力の決まりを確かめたリクエスト
     * @param  AttendanceRecord  $attendanceRecord  URL の id で読んだ勤怠
     * @return RedirectResponse 勤怠詳細の画面への転送
     */
    public function update(AttendanceDetailRequest $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        if ($attendanceRecord->pendingApplication() !== null) {
            return redirect('/attendance/'.$attendanceRecord->id);
        }

        DB::transaction(function () use ($request, $attendanceRecord): void {
            $application = $attendanceRecord->applications()->create([
                'new_clock_in' => $request->validated('new_clock_in'),
                'new_clock_out' => $request->validated('new_clock_out'),
                'comment' => $request->validated('comment'),
            ]);

            $breakOuts = $request->validated('new_break_out', []);

            foreach ($request->validated('new_break_in', []) as $index => $breakIn) {
                $breakOut = $breakOuts[$index] ?? null;

                // 入りと戻りが両方空の行は、追加用の空の行か、消した休憩の行なので、申請の休憩にしない
                if (blank($breakIn) && blank($breakOut)) {
                    continue;
                }

                $application->proposalBreaks()->create([
                    'break_in' => $breakIn,
                    'break_out' => $breakOut,
                ]);
            }
        });

        return redirect('/attendance/'.$attendanceRecord->id);
    }
}
