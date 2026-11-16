<?php

namespace App\Http\Controllers;

use App\Http\Requests\AttendanceDetailRequest;
use App\Http\Requests\StampRequest;
use App\Models\Application;
use App\Models\AttendanceRecord;
use App\Models\User;
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

        DB::transaction(function () use ($user, $action): void {
            $attendanceRecord = $user->findTodayAttendanceRecord();
            $status = User::resolveAttendanceStatus($attendanceRecord);

            if ($action === AttendanceRecord::ACTION_CLOCK_IN && $status === User::STATUS_OFF_DUTY) {
                AttendanceRecord::clockIn($user);
            } elseif ($action === AttendanceRecord::ACTION_CLOCK_OUT && $status === User::STATUS_WORKING) {
                $attendanceRecord->clockOut();
            } elseif ($action === AttendanceRecord::ACTION_BREAK_IN && $status === User::STATUS_WORKING) {
                $attendanceRecord->breakIn();
            } elseif ($action === AttendanceRecord::ACTION_BREAK_OUT && $status === User::STATUS_ON_BREAK) {
                $attendanceRecord->breakOut();
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

        return $this->renderDetail($request, $attendanceRecord);
    }

    /**
     * 勤怠がない日の勤怠詳細の画面を出す。日付が読めないか、今日か今日より後の日なら 404 にする。
     * その日の勤怠がすでにあれば、勤怠の id の勤怠詳細へ移す。なければ、保存していない勤怠で空の勤怠詳細を出す。
     *
     * @param  Request  $request  届いたリクエスト（エラーで戻ったときは送った値を持つ）
     * @param  string  $date  URL の日付（例：2026-11-03）
     * @return View|RedirectResponse 勤怠詳細の画面か、その日の勤怠の勤怠詳細への転送
     */
    public function showDate(Request $request, string $date): View|RedirectResponse
    {
        $attendanceRecord = $this->findOrNewForDate($request, $date);

        if ($attendanceRecord->exists) {
            return redirect('/attendance/'.$attendanceRecord->id);
        }

        return $this->renderDetail($request, $attendanceRecord);
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
        if ($attendanceRecord->pendingApplication() === null) {
            Application::submit($attendanceRecord, $request->validated());
        }

        return redirect('/attendance/'.$attendanceRecord->id);
    }

    /**
     * 勤怠がない日の修正申請を作り、その日の勤怠詳細の画面へ戻す。日付が読めないか、今日か今日より後の日なら 404 にする。
     * その日の勤怠がすでにあれば、申請を作らずに勤怠の id の勤怠詳細へ移す。承認待ちの申請がある日には、画面に修正のボタンが出なくても送られうるので、申請を作らない。
     *
     * @param  AttendanceDetailRequest  $request  入力の決まりを確かめたリクエスト
     * @param  string  $date  URL の日付（例：2026-11-03）
     * @return RedirectResponse その日の勤怠詳細の画面への転送
     */
    public function updateDate(AttendanceDetailRequest $request, string $date): RedirectResponse
    {
        $attendanceRecord = $this->findOrNewForDate($request, $date);

        if ($attendanceRecord->exists) {
            return redirect('/attendance/'.$attendanceRecord->id);
        }

        if ($attendanceRecord->pendingApplication() === null) {
            Application::submit($attendanceRecord, $request->validated());
        }

        return redirect('/attendance/date/'.$date);
    }

    private function renderDetail(Request $request, AttendanceRecord $attendanceRecord): View
    {
        // 承認待ちの申請があるときは読み取り専用で出すので、エラーで戻ったときに送った値は使わない
        $application = $attendanceRecord->pendingApplication();
        $detail = $attendanceRecord->toDetailData($application === null ? $request->session()->getOldInput() : []);
        $detail['application'] = $application;

        return view('user.user-detail', [
            'data' => $detail,
            'user' => $attendanceRecord->user,
        ]);
    }

    private function findOrNewForDate(Request $request, string $date): AttendanceRecord
    {
        $day = AttendanceRecord::parseDateParameter($date);

        // 今日はまだ出勤を打刻でき、今日より後の日はまだ来ていないので、昨日までの日だけを開ける
        abort_if($day === null || ! $day->isBefore(today()), 404);

        return AttendanceRecord::firstOrNew(['user_id' => $request->user()->id, 'date' => $day->toDateString()]);
    }
}
