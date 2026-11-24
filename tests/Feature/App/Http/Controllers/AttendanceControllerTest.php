<?php

namespace Tests\Feature\App\Http\Controllers;

use App\Http\Controllers\AttendanceController;
use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く日時が 2026-06-01（月）08:05 なら、「2026年6月1日(月)」と「08:05」が出ることを確かめる。
     * 2026-06-01 08:05 に一般ユーザーが GET /attendance を送り、index() が今の日付と時刻を表示用の文字にして、200 で user.attendance-register が返り、「2026年6月1日(月)」と「08:05」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_and_time_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 08:05:00'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertViewIs('user.attendance-register');
        $response->assertSee('2026年6月1日(月)');
        $response->assertSee('08:05');
    }

    /**
     * 打刻の画面を作る途中で0時を過ぎても、画面に渡す日付と時刻が同じ1つの今の日時から作られることを確かめる。
     * テストから、最初に読む今の日時だけが 2026-11-30 23:59:59 で2回目からは 2026-12-01 00:00:00 になる時計で、一般ユーザーのリクエストを index() に渡して呼び、index() が今の日時を1回だけ取って、formattedDate が「2026年11月30日(月)」、formattedTime が「23:59」かを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_and_time_are_taken_once(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $request = Request::create('/attendance');
        $request->setUserResolver(fn (): User => $user);
        $isFirstRead = true;
        $this->travelTo(function () use (&$isFirstRead): Carbon {
            $now = Carbon::parse($isFirstRead ? '2026-11-30 23:59:59' : '2026-12-01 00:00:00');
            $isFirstRead = false;

            return $now;
        });

        $view = app(AttendanceController::class)->index($request);

        $this->assertSame('2026年11月30日(月)', $view->getData()['formattedDate']);
        $this->assertSame('23:59', $view->getData()['formattedTime']);
    }

    /**
     * 勤務外で action が clock_in なら、自分の今日の勤怠が1行できることを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で clock_in を送り、store() が出勤の if に入って、/attendance に移り、今日の勤怠が1行でき、clock_in が 09:00:00 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_is_written_when_off_duty(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_IN]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
        ]);
    }

    /**
     * 勤務外で action が clock_out なら、勤怠と休憩の行ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で clock_out を送り、store() が退勤の if の「出勤中か」で止めて、/attendance に移り、勤怠と休憩の行ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_is_ignored_when_off_duty(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 18:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('breaks', 0);
    }

    /**
     * 勤務外で action が break_in なら、勤怠と休憩の行ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で break_in を送り、store() が休憩入の if の「出勤中か」で止めて、/attendance に移り、勤怠と休憩の行ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_is_ignored_when_off_duty(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_BREAK_IN]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('breaks', 0);
    }

    /**
     * 勤務外で action が break_out なら、勤怠と休憩の行ができないことを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance で break_out を送り、store() が休憩戻の if の「休憩中か」で止めて、/attendance に移り、勤怠と休憩の行ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_is_ignored_when_off_duty(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 13:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_BREAK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 0);
        $this->assertDatabaseCount('breaks', 0);
    }

    /**
     * 出勤中で action が clock_out なら、今日の勤怠の clock_out が書かれることを確かめる。
     * 09:00 出勤の今日の勤怠があるときに、一般ユーザーが 18:00 に POST /attendance で clock_out を送り、store() が退勤の if に入って、/attendance に移り、clock_out が 18:00:00 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_is_written_when_working(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 18:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_out' => '18:00:00',
        ]);
    }

    /**
     * 出勤中で action が break_in なら、今日の勤怠に休憩が1行できることを確かめる。
     * 09:00 出勤の今日の勤怠があるときに、一般ユーザーが 12:00 に POST /attendance で break_in を送り、store() が休憩入の if に入って、/attendance に移り、休憩が1行でき、break_in が 12:00:00 で、break_out が null になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_is_written_when_working(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_BREAK_IN]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('breaks', 1);
        $this->assertDatabaseHas('breaks', [
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => null,
        ]);
    }

    /**
     * 休憩中で action が break_out なら、その休憩の break_out が書かれることを確かめる。
     * 12:00 からの戻りのない休憩があるときに、一般ユーザーが 13:00 に POST /attendance で break_out を送り、store() が休憩戻の if に入って、/attendance に移り、break_out が 13:00:00 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_is_written_when_on_break(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 13:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);
        $attendanceBreak = AttendanceBreak::factory()->create([
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => null,
        ]);

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_BREAK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('breaks', [
            'id' => $attendanceBreak->id,
            'break_out' => '13:00:00',
        ]);
    }

    /**
     * 承認待ちの申請があれば、エラーで戻ったときの送った値があっても、送った 10:00 が出ず、勤怠の 09:00 が読み取り専用で出ることを確かめる。
     * 出勤 10:00 でエラーで戻った後に承認待ちの申請ができたときに、一般ユーザーが GET /attendance/{id} を送り、renderDetail() が送った値を渡さずに、200 で user.user-detail が返り、10:00 が出ず、09:00 が読み取り専用で出て、「承認待ちのため修正はできません。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_old_input_is_ignored_when_pending(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        $this->actingAs($user)
            ->from('/attendance/'.$attendanceRecord->id)
            ->post('/attendance/'.$attendanceRecord->id, [
                'new_clock_in' => '10:00',
                'new_clock_out' => '18:00',
                'comment' => '',
            ]);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertDontSee('value="10:00"', false);
        $response->assertSee('value="09:00"', false);
        $response->assertSee('承認待ちのため修正はできません。');
    }

    /**
     * その日の自分の勤怠があるなら、/attendance/{その勤怠の id} に移ることを確かめる。
     * 自分の 2026-06-02 の勤怠があるときに、一般ユーザーが GET /attendance/date/2026-06-02 を送り、showDate() がその勤怠を見つけて、その勤怠の詳細に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_with_record_redirects_to_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
        ]);

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-02');

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
    }

    /**
     * その日に他の人の勤怠だけがあるなら、自分の勤怠がない日として出勤、退勤、休憩、備考の入力欄が空で出て、他の人の 09:00 が出ないことを確かめる。
     * 他の人の 2026-06-02 の勤怠（09:00〜18:00）だけがあるときに、一般ユーザーが GET /attendance/date/2026-06-02 を送り、findOrNewForDate() が開いた人の勤怠だけを探して、200 で出勤、退勤、休憩、備考の入力欄が空で出て、09:00 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_record_is_not_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $otherUser->id,
            'date' => '2026-06-02',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-02');

        $response->assertOk();
        $response->assertDontSee('value="09:00"', false);
        $response->assertSee('id="new_clock_in" type="text" name="new_clock_in" value=""', false);
        $response->assertSee('name="new_clock_out" value=""', false);
        $response->assertSee('name="new_break_in[0]" value=""', false);
        $response->assertSee('name="new_break_out[0]" value=""', false);
        $response->assertSee('id="comment" name="comment"></textarea>', false);
    }

    /**
     * その日に自分の他の日の勤怠だけがあるなら、出勤、退勤、休憩、備考の入力欄が空で出ることを確かめる。
     * 自分の 2026-06-01 の勤怠だけがあるときに、一般ユーザーが GET /attendance/date/2026-06-02 を送り、findOrNewForDate() が URL の日付の勤怠だけを探して、200 で出勤、退勤、休憩、備考の入力欄が空で出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_day_record_is_not_used(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-02');

        $response->assertOk();
        $response->assertSee('id="new_clock_in" type="text" name="new_clock_in" value=""', false);
        $response->assertSee('name="new_clock_out" value=""', false);
        $response->assertSee('name="new_break_in[0]" value=""', false);
        $response->assertSee('name="new_break_out[0]" value=""', false);
        $response->assertSee('id="comment" name="comment"></textarea>', false);
    }

    /**
     * 開く日が 2026-06-03 で URL の日付が昨日の 2026-06-02 なら、200 で開けることを確かめる。
     * 2026-06-02 の勤怠がないときに、一般ユーザーが 2026-06-03 に GET /attendance/date/2026-06-02 を送り、findOrNewForDate() が昨日の日付を通して、200 で user.user-detail が返り、「2026年」と「6月2日」が出て、送り先が /attendance/date/2026-06-02 のフォームが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_of_day_without_record_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-02');

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('2026年');
        $response->assertSee('6月2日');
        $response->assertSee('action="'.url('/attendance/date/2026-06-02').'"', false);
    }

    /**
     * 開く日が 2026-06-03 で URL の日付が今日の 2026-06-03 なら、404 が返ることを確かめる。
     * 一般ユーザーが 2026-06-03 に GET /attendance/date/2026-06-03 を送り、findOrNewForDate() が今日以降の日を止めて、404 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_today_and_future_return_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-03');

        $response->assertNotFound();
    }

    /**
     * 勤怠の承認待ちの申請がないなら、申請が1行できることを確かめる。
     * 承認待ちの申請がないときに、一般ユーザーが自分の勤怠（2026-06-01）に POST /attendance/{id} で正しい値を送り、update() が申請を作って、/attendance/{id} に移り、自分の 2026-06-01 の申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_is_created_without_pending(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseHas('applications', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);
    }

    /**
     * 勤怠の承認待ちの申請があるなら、申請が1行のままで増えないことを確かめる。
     * 承認待ちの申請があるときに、一般ユーザーが自分の勤怠（2026-06-01）に POST /attendance/{id} で正しい値を送り、update() が申請を作らずに、/attendance/{id} に移り、申請が1行のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_record_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * その日の自分の勤怠があるなら、申請ができずに /attendance/{その勤怠の id} に移ることを確かめる。
     * 自分の 2026-06-02 の勤怠があるときに、一般ユーザーが POST /attendance/date/2026-06-02 で正しい値を送り、updateDate() が申請を作らずに、その勤怠の詳細に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_with_record_does_not_create_application(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
        ]);

        $response = $this->actingAs($user)->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * その日の勤怠がなくても承認待ちの申請があるなら、申請が1件のままであることを確かめる。
     * 2026-06-02 の承認待ちの申請だけがあるときに、一般ユーザーが POST /attendance/date/2026-06-02 で正しい値を送り、updateDate() が申請を作らずに、/attendance/date/2026-06-02 に移り、申請が1件のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_second_application_is_rejected(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
        ]);

        $response = $this->actingAs($user)->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/date/2026-06-02');
        $this->assertDatabaseCount('applications', 1);
    }
}
