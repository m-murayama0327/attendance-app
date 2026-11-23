<?php

namespace Tests\Feature\App\Http\Controllers\Admin;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * その日に管理者の勤怠があるなら、管理者の名前の行が 09:00 と 18:00 で出ることを確かめる。
     * 管理者の 2026-06-01 の勤怠（09:00〜18:00）があるときに、管理者が同じ日に GET /admin/attendance/list を送り、index() が管理者も一覧に入れて、200 で名前の行に 09:00 と 18:00 が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_record_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true, 'name' => '管理 太郎']);
        $this->travelTo(Carbon::parse('2026-06-01 20:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $admin->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertOk();
        $response->assertSeeInOrder(['管理 太郎', '09:00', '18:00']);
    }

    /**
     * その日に一般ユーザーの勤怠があるなら、その人の名前、「09:00」「18:00」「1:00」、/attendance/{id} のリンクが出ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00）があるときに、管理者が同じ日に GET /admin/attendance/list を送り、index() がその日の勤怠を探して、200 で行の値と total_time 08:00 が渡るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_row_values_are_shown_admin_attendance_list(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田 太郎']);
        $this->travelTo(Carbon::parse('2026-06-01 20:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
        $attendanceRecord->recalculate();

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertOk();
        $response->assertSeeInOrder(['山田 太郎', '09:00', '18:00', '1:00']);
        $response->assertSee('href="'.url('/attendance/'.$attendanceRecord->id).'"', false);
        $response->assertViewHas('attendanceRecords', fn ($attendanceRecords): bool => $attendanceRecords->first()->total_time === '08:00');
    }

    /**
     * 一般ユーザー B に他の日の勤怠だけがあっても、表示する日 2026-06-01 の一般ユーザー A の 09:00 と 18:00 が出て、B の 07:15 と 16:45 が出ないことを確かめる。
     * A の 2026-06-01（09:00〜18:00）と B の 2026-05-29（07:15〜16:45）の勤怠だけがあるときに、管理者が 2026-06-01 に GET /admin/attendance/list を送り、index() がその日の勤怠だけを探して、200 で admin.admin-attendance-list が返り、A の 09:00 と 18:00 が出て、B の 07:15 と 16:45 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_only_records_of_the_day_are_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $firstUser = User::factory()->create(['admin_status' => false, 'name' => '山田 太郎']);
        $secondUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 20:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $firstUser->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceRecord::factory()->create([
            'user_id' => $secondUser->id,
            'date' => '2026-05-29',
            'clock_in' => '07:15:00',
            'clock_out' => '16:45:00',
        ]);

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-attendance-list');
        $response->assertSeeInOrder(['山田 太郎', '09:00', '18:00']);
        $response->assertDontSee('07:15');
        $response->assertDontSee('16:45');
    }

    /**
     * id がいない id（999）なら、404 が返ることを確かめる。
     * 管理者が GET /admin/attendance/staff/999 を送り、staff() が一般ユーザーの中から findOrFail() で探して、404 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_id_returns_not_found(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/999');

        $response->assertNotFound();
    }

    /**
     * 勤怠の承認待ちの申請があっても、読み取り専用にならずに修正のフォームが出ることを確かめる。
     * 一般ユーザーの勤怠に承認待ちの申請があるときに、管理者が GET /attendance/{id} を送り、renderDetail() が申請を見ずに、200 で admin.admin-detail が返り、修正のフォームが出て、承認待ちの文が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_record_is_editable(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($admin)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('name="new_clock_in"', false);
        $response->assertDontSee('承認待ちのため修正はできません。');
    }

    /**
     * 承認待ちの申請があっても、エラーで戻ったときの送った 10:00 が欄に残り、休憩の行の番号がエラーと合い、空の行が1つだけ出ることを確かめる。
     * 承認待ちの申請がある勤怠に出勤 10:00 などを送ってエラーで戻った後に、管理者が GET /attendance/{id} を送り、renderDetail() が送った値を渡して、200 で admin.admin-detail が返り、10:00 が残り、1行目の休憩の欄の後に「休憩時間が不適切な値です」が出て、2行目の休憩の欄が空で出て、3行目の休憩の欄が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_old_input_is_kept_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);
        $this->actingAs($admin)
            ->from('/attendance/'.$attendanceRecord->id)
            ->post('/attendance/'.$attendanceRecord->id, [
                'new_clock_in' => '10:00',
                'new_clock_out' => '18:00',
                'new_break_in' => ['19:00', ''],
                'new_break_out' => ['13:00', ''],
                'comment' => '',
            ]);

        $response = $this->actingAs($admin)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('value="10:00"', false);
        $response->assertSeeInOrder(['name="new_break_in[0]"', '休憩時間が不適切な値です', 'name="new_break_in[1]"'], false);
        $response->assertSee('name="new_break_in[1]" value=""', false);
        $response->assertDontSee('name="new_break_in[2]"', false);
    }

    /**
     * その日のその人の勤怠があるなら、/attendance/{その勤怠の id} に移ることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠があるときに、管理者が GET /admin/attendance/staff/{id}/date/2026-06-02 を送り、showDate() がその勤怠を見つけて、その勤怠の詳細に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_with_record_redirects_to_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
        ]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
    }

    /**
     * 開く日が 2026-06-03 で URL の日付が昨日の 2026-06-02 なら、200 で開けることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/staff/{id}/date/2026-06-02 を送り、findOrNewForDate() が昨日の日付を通して、200 で admin.admin-detail が返り、「山田 太郎」が出て、送り先が /admin/attendance/staff/{id}/date/2026-06-02 のフォームが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_opens_day_without_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田 太郎']);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('山田 太郎');
        $response->assertSee('action="'.url('/admin/attendance/staff/'.$user->id.'/date/2026-06-02').'"', false);
    }

    /**
     * 開く日が 2026-06-03 で URL の日付が今日の 2026-06-03 なら、404 が返ることを確かめる。
     * 管理者が 2026-06-03 に GET /admin/attendance/staff/{id}/date/2026-06-03 を送り、findOrNewForDate() が今日以降の日を止めて、404 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_today_and_future_return_not_found(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-03');

        $response->assertNotFound();
    }

    /**
     * id がいない id（999）なら、404 が返ることを確かめる。
     * 管理者が GET /admin/attendance/staff/999/date/2026-06-02 を送り、findOrNewForDate() が一般ユーザーの中から findOrFail() で探して、404 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_id_returns_not_found_on_staff_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/999/date/2026-06-02');

        $response->assertNotFound();
    }

    /**
     * id が管理者の id なら、404 が返ることを確かめる。
     * 管理者が GET /admin/attendance/staff/{管理者の id}/date/2026-06-02 を送り、findOrNewForDate() が一般ユーザーだけから探して、404 が返るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_id_returns_not_found_on_staff_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$admin->id.'/date/2026-06-02');

        $response->assertNotFound();
    }

    /**
     * その日に他の一般ユーザーの勤怠だけがあるなら、URL の id の人の名前と空の入力欄が出て、他の人の 09:00 が出ないことを確かめる。
     * 他の一般ユーザーの 2026-06-02 の勤怠（09:00〜18:00）だけがあるときに、管理者が GET /admin/attendance/staff/{id}/date/2026-06-02 を送り、findOrNewForDate() が id の人の勤怠だけを探して、200 で「山田 太郎」が出て、attendanceRecord の clock_in、clock_out、breaks、comment が空で渡り、09:00 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_record_is_not_used_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田 太郎']);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $otherUser->id,
            'date' => '2026-06-02',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertOk();
        $response->assertSee('山田 太郎');
        $response->assertViewHas('attendanceRecord', fn (array $detail): bool => $detail['clock_in'] === null
            && $detail['clock_out'] === null
            && $detail['breaks'] === []
            && $detail['comment'] === null);
        $response->assertDontSee('value="09:00"', false);
    }

    /**
     * その日にその人の他の日の勤怠だけがあるなら、入力欄が空で出ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠だけがあるときに、管理者が GET /admin/attendance/staff/{その人の id}/date/2026-06-02 を送り、findOrNewForDate() が URL の日付の勤怠だけを探して、200 でattendanceRecord の clock_in、clock_out、breaks、comment が空で渡るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_day_record_is_not_used_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertOk();
        $response->assertViewHas('attendanceRecord', fn (array $detail): bool => $detail['clock_in'] === null
            && $detail['clock_out'] === null
            && $detail['breaks'] === []
            && $detail['comment'] === null);
    }

    /**
     * 勤怠の承認待ちの申請があっても、勤怠の clock_in が 08:30:00 に変わり、申請は承認待ちのままであることを確かめる。
     * 一般ユーザーの勤怠（09:00〜18:00）に承認待ちの申請があるときに、管理者が POST /attendance/{id} で出勤 08:30 などを送り、update() が書き換えて、/attendance/{id} に移り、clock_in が 08:30:00 になり、申請が承認待ちのままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_record_can_be_updated(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
        ]);

        $response = $this->actingAs($admin)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '08:30:00',
        ]);
        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'is_approved' => false,
        ]);
    }

    /**
     * その日のその人の勤怠があるなら、勤怠は1件のままで 09:00〜18:00 から変わらないことを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠（09:00〜18:00）があるときに、管理者が POST /admin/attendance/staff/{id}/date/2026-06-02 で出勤 08:00 などを送り、updateDate() が書き換えずに、/attendance/{id} に移り、勤怠が1件のままで変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_does_not_overwrite_existing_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $response = $this->actingAs($admin)->post('/admin/attendance/staff/'.$user->id.'/date/2026-06-02', [
            'new_clock_in' => '08:00',
            'new_clock_out' => '17:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }
}
