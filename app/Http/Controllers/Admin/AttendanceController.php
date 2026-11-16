<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttendanceDetailRequest;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $date = AttendanceRecord::parseDateQuery($request->query('date'));

        $attendanceRecords = AttendanceRecord::where('date', $date->toDateString())->get();

        $users = User::general()
            ->orWhereIn('id', $attendanceRecords->pluck('user_id'))
            ->orderByRegistration()
            ->get();

        return view('admin.admin-attendance-list', [
            'date' => $date,
            'previousDay' => $date->copy()->subDay()->toDateString(),
            'nextDay' => $date->copy()->addDay()->toDateString(),
            'users' => $users,
            'attendanceRecords' => $attendanceRecords,
        ]);
    }

    /**
     * スタッフ別勤怠一覧を出す。id の一般ユーザーについて、?date= の値から表示する月を決め、その月の全部の日の行を渡す。
     * id が一般ユーザーでない（管理者か、いない）ときは 404 にする。
     *
     * @param  Request  $request  届いたリクエスト（?date= に表示する月）
     * @param  int  $id  URL の一般ユーザーの id
     * @return View スタッフ別勤怠一覧の画面
     */
    public function staff(Request $request, int $id): View
    {
        $user = User::general()->findOrFail($id);
        $monthView = AttendanceRecord::resolveMonthView($request->query('date'));

        return view('admin.staff-attendance-list', [
            'user' => $user,
            'date' => $monthView['date'],
            'previousMonth' => $monthView['previousMonth'],
            'nextMonth' => $monthView['nextMonth'],
            'formattedAttendanceRecords' => AttendanceRecord::monthlyRows($user, $monthView['date']),
        ]);
    }

    /**
     * 管理者の勤怠詳細の画面を出す。勤怠がなければ、ルートモデルバインディングが 404 にする。
     * 承認待ちの申請があっても修正のフォームを出し、入力の誤りで戻ったときは、送った値で時刻、休憩、備考を置き換える。
     *
     * @param  Request  $request  届いたリクエスト（エラーで戻ったときは送った値を持つ）
     * @param  AttendanceRecord  $attendanceRecord  URL の id で読んだ勤怠
     * @return View 管理者の勤怠詳細の画面
     */
    public function show(Request $request, AttendanceRecord $attendanceRecord): View
    {
        return $this->renderDetail($request, $attendanceRecord);
    }

    /**
     * 一般ユーザーの勤怠がない日の、管理者の勤怠詳細の画面を出す。id が一般ユーザーでないか、日付が読めないか、今日か今日より後の日なら 404 にする。
     * その人のその日の勤怠がすでにあれば、勤怠の id の勤怠詳細へ移す。なければ、保存していない勤怠で空の勤怠詳細を出す。
     *
     * @param  Request  $request  届いたリクエスト（エラーで戻ったときは送った値を持つ）
     * @param  int  $id  URL の一般ユーザーの id
     * @param  string  $date  URL の日付（例：2026-11-03）
     * @return View|RedirectResponse 管理者の勤怠詳細の画面か、その日の勤怠の勤怠詳細への転送
     */
    public function showDate(Request $request, int $id, string $date): View|RedirectResponse
    {
        $attendanceRecord = $this->findOrNewForDate($id, $date);

        if ($attendanceRecord->exists) {
            return redirect('/attendance/'.$attendanceRecord->id);
        }

        return $this->renderDetail($request, $attendanceRecord);
    }

    /**
     * 勤怠を直接直し、勤怠詳細の画面へ戻す。承認待ちの申請があっても直し、申請は承認待ちのまま残す。
     *
     * @param  AttendanceDetailRequest  $request  勤怠を直してよい人と入力の決まりを確かめたリクエスト
     * @param  AttendanceRecord  $attendanceRecord  URL の id で読んだ勤怠
     * @return RedirectResponse 勤怠詳細の画面への転送
     */
    public function update(AttendanceDetailRequest $request, AttendanceRecord $attendanceRecord): RedirectResponse
    {
        $attendanceRecord->applyAdminEdit($request->validated());

        return redirect('/attendance/'.$attendanceRecord->id);
    }

    /**
     * 一般ユーザーの勤怠がない日の勤怠を直接修正で作り、作った勤怠の勤怠詳細の画面へ移す。id が一般ユーザーでないか、日付が読めないか、今日か今日より後の日なら 404 にする。
     * その人のその日の勤怠がすでにあれば、作らずに勤怠の id の勤怠詳細へ移す。
     *
     * @param  AttendanceDetailRequest  $request  入力の決まりを確かめたリクエスト
     * @param  int  $id  URL の一般ユーザーの id
     * @param  string  $date  URL の日付（例：2026-11-03）
     * @return RedirectResponse 勤怠詳細の画面への転送
     */
    public function updateDate(AttendanceDetailRequest $request, int $id, string $date): RedirectResponse
    {
        $attendanceRecord = $this->findOrNewForDate($id, $date);

        if (! $attendanceRecord->exists) {
            $attendanceRecord = AttendanceRecord::createFromAdminEdit($attendanceRecord->user, $attendanceRecord->date, $request->validated());
        }

        return redirect('/attendance/'.$attendanceRecord->id);
    }

    private function renderDetail(Request $request, AttendanceRecord $attendanceRecord): View
    {
        return view('admin.admin-detail', [
            'attendanceRecord' => $attendanceRecord->toDetailData($request->session()->getOldInput()),
            'user' => $attendanceRecord->user,
        ]);
    }

    private function findOrNewForDate(int $id, string $date): AttendanceRecord
    {
        $user = User::general()->findOrFail($id);
        $day = AttendanceRecord::parseDateParameter($date);

        // 今日はまだ出勤を打刻でき、今日より後の日はまだ来ていないので、昨日までの日だけを開ける
        abort_if($day === null || ! $day->isBefore(today()), 404);

        return AttendanceRecord::firstOrNew(['user_id' => $user->id, 'date' => $day->toDateString()]);
    }
}
