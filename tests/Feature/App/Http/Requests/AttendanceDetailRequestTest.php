<?php

namespace Tests\Feature\App\Http\Requests;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttendanceDetailRequestTest extends TestCase
{
    use RefreshDatabase;

    private AttendanceRecord $attendanceRecord;

    /**
     * URL の勤怠がない日なら、申請が1行でき、/attendance/date/2026-06-02 に移ることを確かめる。
     * 2026-06-02 の勤怠がないときに、一般ユーザーが 2026-06-03 に POST /attendance/date/2026-06-02 で正しい値を送り、authorize() が通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_without_record_is_authorized(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

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

    /**
     * 他の一般ユーザーが他の人の勤怠に出勤と備考を空にして送ると、403 が返ることを確かめる。
     * 一般ユーザーが他の人の勤怠に POST /attendance/{id} で出勤と備考を空にして送り、authorize() が入力を確かめる前に止めて、403 が返り、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_gets_forbidden_for_invalid_values(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherAttendanceRecord = AttendanceRecord::factory()->create(['date' => '2026-06-01']);

        $response = $this->actingAs($user)->post('/attendance/'.$otherAttendanceRecord->id, [
            'new_clock_in' => '',
            'new_clock_out' => '18:00',
            'comment' => '',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * name と new_date を足して送っても、users の name と勤怠の date が変わらないことを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で name と new_date を足して送り、rules() が2つを確かめた値に入れずに、エラーが出ず、/attendance/{id} に移り、申請が1行でき、users の name と勤怠の date が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_read_only_fields_are_ignored(): void
    {
        $response = $this->postAsUser(['name' => '別の名前', 'new_date' => '2026-06-05']);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/attendance/'.$this->attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseMissing('users', ['name' => '別の名前']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'date' => '2026-06-01',
        ]);
    }

    /**
     * 出勤 09:00、退勤 09:01 なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で出勤 09:00、退勤 09:01 を送り、rules() が new_clock_out の after:new_clock_in で通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_one_minute_after_clock_in_is_accepted(): void
    {
        $response = $this->postAsUser([
            'new_clock_in' => '09:00',
            'new_clock_out' => '09:01',
            'new_break_in' => [''],
            'new_break_out' => [''],
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * 休憩の入り 12:00、戻り 12:01 なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入り 12:00、戻り 12:01 を送り、rules() が new_break_out.* の after:new_break_in.* で通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_one_minute_after_break_in_is_accepted(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['12:01', '']]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * 休憩の入りが出勤と同じ 09:00 なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを出勤と同じ 09:00 にして送り、rules() が new_break_in.* の after_or_equal:new_clock_in で通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_on_boundary_is_accepted(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['09:00', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * 休憩の戻りが退勤と同じ 18:00 なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の戻りを退勤と同じ 18:00 にして送り、rules() が new_break_out.* の before_or_equal:new_clock_out で通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_on_boundary_is_accepted(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['18:00', '']]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * comment が255文字なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で comment を255文字にして送り、rules() が comment の max:255 で通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_of_max_length_is_accepted(): void
    {
        $response = $this->postAsUser(['comment' => str_repeat('あ', 255)]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * new_clock_in が空なら、new_clock_in に「出勤時間を入力してください」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_clock_in を空にして送り、rules() が new_clock_in の required で止めて、「出勤時間を入力してください」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_is_required(): void
    {
        $response = $this->postAsUser(['new_clock_in' => '']);

        $response->assertSessionHasErrors(['new_clock_in' => '出勤時間を入力してください']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * new_clock_in が 9:00 なら、new_clock_in に「出勤時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_clock_in を 9:00 にして送り、rules() が new_clock_in の date_format:H:i で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_format(): void
    {
        $response = $this->postAsUser(['new_clock_in' => '9:00']);

        $response->assertSessionHasErrors(['new_clock_in' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 出勤 19:00、退勤 18:00 なら、new_clock_out に「出勤時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で出勤 19:00、退勤 18:00 を送り、rules() が new_clock_out の after:new_clock_in で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_after_clock_out_application_request(): void
    {
        $response = $this->postAsUser(['new_clock_in' => '19:00', 'new_clock_out' => '18:00']);

        $response->assertSessionHasErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人が管理者で出勤 19:00、退勤 18:00 でも、new_clock_out に「出勤時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 管理者が一般ユーザーの勤怠に POST /attendance/{id} で出勤 19:00、退勤 18:00 を送り、rules() が new_clock_out の after:new_clock_in で止めて、エラーが出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_after_clock_out_admin_attendance_update(): void
    {
        $response = $this->postAsAdmin(['new_clock_in' => '19:00', 'new_clock_out' => '18:00']);

        $response->assertSessionHasErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * new_clock_out が空なら、new_clock_out に「退勤時間を入力してください」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_clock_out を空にして送り、rules() が new_clock_out の required で止めて、「退勤時間を入力してください」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_is_required(): void
    {
        $response = $this->postAsUser(['new_clock_out' => '']);

        $response->assertSessionHasErrors(['new_clock_out' => '退勤時間を入力してください']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * new_clock_out が 18:00:00 なら、new_clock_out に「出勤時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_clock_out を 18:00:00 にして送り、rules() が new_clock_out の date_format:H:i で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_format(): void
    {
        $response = $this->postAsUser(['new_clock_out' => '18:00:00']);

        $response->assertSessionHasErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 出勤と退勤が同じ 09:00 なら、new_clock_out に「出勤時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で出勤と退勤を同じ 09:00 にして送り、rules() が new_clock_out の after:new_clock_in で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_clock_in_and_out_is_rejected(): void
    {
        $response = $this->postAsUser(['new_clock_in' => '09:00', 'new_clock_out' => '09:00']);

        $response->assertSessionHasErrors(['new_clock_out' => '出勤時間もしくは退勤時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 出勤 09:00 で休憩の入りが 08:59 なら、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを 08:59 にして送り、rules() が new_break_in.* の after_or_equal:new_clock_in で止めて、「休憩時間が不適切な値です」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_before_clock_in(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['08:59', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasErrors(['new_break_in.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 退勤 18:00 で休憩の入りが 18:01、戻りが 13:00 なら、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入り 18:01、戻り 13:00 を送り、rules() が new_break_in.* の before_or_equal:new_clock_out で止めて、入りと戻りにエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_after_clock_out_application_request(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['18:01', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasErrors([
            'new_break_in.0' => '休憩時間が不適切な値です',
            'new_break_out.0' => '休憩時間が不適切な値です',
        ]);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人が管理者で休憩の入りが 18:01、戻りが 13:00 でも、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 管理者が一般ユーザーの勤怠に POST /attendance/{id} で休憩の入り 18:01、戻り 13:00 を送り、rules() が new_break_in.* の before_or_equal:new_clock_out で止めて、入りと戻りにエラーが出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_after_clock_out_admin_attendance_update(): void
    {
        $response = $this->postAsAdmin(['new_break_in' => ['18:01', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasErrors([
            'new_break_in.0' => '休憩時間が不適切な値です',
            'new_break_out.0' => '休憩時間が不適切な値です',
        ]);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 休憩の入りが退勤と同じ 18:00 で戻りが空なら、new_break_in.0 にエラーが出ないことを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを 18:00、戻りを空にして送り、rules() が before_or_equal:new_clock_out で入りを通して、戻りにだけ「休憩時間が不適切な値です」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_on_clock_out_without_break_out_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['18:00', ''], 'new_break_out' => ['', '']]);

        $response->assertSessionDoesntHaveErrors('new_break_in.0');
        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩の入りが空で戻りが 13:00 なら、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを空、戻りを 13:00 にして送り、rules() が new_break_in.* の required_with:new_break_out.* で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_only_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasErrors(['new_break_in.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩の入りが 12:00:00 なら、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを 12:00:00 にして送り、rules() が new_break_in.* の date_format:H:i で止めて、「休憩時間が不適切な値です」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_format(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00:00', ''], 'new_break_out' => ['13:00', '']]);

        $response->assertSessionHasErrors(['new_break_in.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * new_break_in が文字 abc なら、/attendance/{id} へ戻り、new_break_in にエラーが出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_break_in を文字 abc にして送り、rules() が new_break_in の array で止めて、/attendance/{id} へ戻り、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_string_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => 'abc']);

        $response->assertRedirect('/attendance/'.$this->attendanceRecord->id);
        $response->assertSessionHasErrors('new_break_in');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * new_break_out が文字 abc なら、/attendance/{id} へ戻り、new_break_out にエラーが出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で new_break_out を文字 abc にして送り、rules() が new_break_out の array で止めて、/attendance/{id} へ戻り、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_string_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_out' => 'abc']);

        $response->assertRedirect('/attendance/'.$this->attendanceRecord->id);
        $response->assertSessionHasErrors('new_break_out');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * comment が配列 ['a'] なら、/attendance/{id} へ戻り、comment にエラーが出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で comment を配列 ['a'] にして送り、rules() が comment の string で止めて、/attendance/{id} へ戻り、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_array_is_rejected(): void
    {
        $response = $this->postAsUser(['comment' => ['a']]);

        $response->assertRedirect('/attendance/'.$this->attendanceRecord->id);
        $response->assertSessionHasErrors('comment');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩の入りが 12:00 で戻りが空なら、new_break_out.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りを 12:00、戻りを空にして送り、rules() が new_break_out.* の required_with:new_break_in.* で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_only_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['', '']]);

        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩の戻りが abc なら、new_break_out.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の戻りを abc にして送り、rules() が new_break_out.* の date_format:H:i で止めて、「休憩時間が不適切な値です」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_format(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['abc', '']]);

        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 退勤 18:00 で休憩の戻りが 18:30 なら、new_break_out.0 に「休憩時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の戻りを 18:30 にして送り、rules() が new_break_out.* の before_or_equal:new_clock_out で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_after_clock_out_application_request(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['18:30', '']]);

        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人が管理者で休憩の入りが 12:00、戻りが 18:30 でも、new_break_out.0 に「休憩時間もしくは退勤時間が不適切な値です」が出ることを確かめる。
     * 管理者が一般ユーザーの勤怠に POST /attendance/{id} で休憩の戻りを 18:30 にして送り、rules() が new_break_out.* の before_or_equal:new_clock_out で止めて、エラーが出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_after_clock_out_admin_attendance_update(): void
    {
        $response = $this->postAsAdmin(['new_break_in' => ['12:00', ''], 'new_break_out' => ['18:30', '']]);

        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間もしくは退勤時間が不適切な値です']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * 1行目が休憩 12:00〜13:00、2行目が入り 15:00、戻り 12:30 なら、new_break_out.1 にエラーが出て、1行目にエラーが出ないことを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で2行目に入り 15:00、戻り 12:30 を足して送り、rules() が after:new_break_in.* で同じ行の入りと比べて、2行目の戻りにだけエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_rules_compare_same_row(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', '15:00'], 'new_break_out' => ['13:00', '12:30']]);

        $response->assertSessionHasErrors('new_break_out.1');
        $response->assertSessionDoesntHaveErrors(['new_break_in.0', 'new_break_out.0']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩の入りと戻りが同じ 12:00 なら、new_break_out.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の入りと戻りを同じ 12:00 にして送り、rules() が new_break_out.* の after:new_break_in.* で止めて、エラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_equal_to_break_in_is_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', ''], 'new_break_out' => ['12:00', '']]);

        $response->assertSessionHasErrors(['new_break_out.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * comment が空なら、comment に「備考を記入してください」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で comment を空にして送り、rules() が comment の required で止めて、「備考を記入してください」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_is_required_application_request(): void
    {
        $response = $this->postAsUser(['comment' => '']);

        $response->assertSessionHasErrors(['comment' => '備考を記入してください']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人が管理者で comment が空でも、comment に「備考を記入してください」が出ることを確かめる。
     * 管理者が一般ユーザーの勤怠に POST /attendance/{id} で comment を空にして送り、rules() が comment の required で止めて、「備考を記入してください」が出て、勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_is_required_admin_attendance_update(): void
    {
        $response = $this->postAsAdmin(['comment' => '']);

        $response->assertSessionHasErrors(['comment' => '備考を記入してください']);
        $this->assertAttendanceRecordIsUnchanged();
    }

    /**
     * comment が256文字なら、comment に「備考は255文字以内で入力してください」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で comment を256文字にして送り、rules() が comment の max:255 で止めて、「備考は255文字以内で入力してください」が出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_max_length(): void
    {
        $response = $this->postAsUser(['comment' => str_repeat('あ', 256)]);

        $response->assertSessionHasErrors(['comment' => '備考は255文字以内で入力してください']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩が 12:00〜13:00 と 13:00〜13:30 なら、エラーにならず、申請が1行できることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩 12:00〜13:00 と 13:00〜13:30 を送り、withValidator() が重なっていないとして通して、申請が1行できるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_adjacent_breaks_are_accepted(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', '13:00'], 'new_break_out' => ['13:00', '13:30']]);

        $response->assertSessionHasNoErrors();
        $this->assertDatabaseCount('applications', 1);
    }

    /**
     * 休憩が 12:00〜13:00 と 12:59〜13:30 なら、new_break_in.1 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩 12:00〜13:00 と 12:59〜13:30 を送り、withValidator() が重なっているとして止めて、2行目の入りにエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_overlapping_breaks_are_rejected(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', '12:59'], 'new_break_out' => ['13:00', '13:30']]);

        $response->assertSessionHasErrors(['new_break_in.1' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 1行目が休憩 12:00〜13:00、2行目が入り 12:30、戻り abc なら、new_break_in.1 に重なりのエラーが出ないことを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で2行目に入り 12:30、戻り abc を足して送り、withValidator() が重なりを比べずに、new_break_out.1 にだけエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_not_compared_with_other_errors(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:00', '12:30'], 'new_break_out' => ['13:00', 'abc']]);

        $response->assertSessionDoesntHaveErrors('new_break_in.1');
        $response->assertSessionHasErrors('new_break_out.1');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 1行目が休憩 12:30〜13:30、2行目が休憩 12:00〜13:00 なら、new_break_in.0 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩 12:30〜13:30、12:00〜13:00 の順に送り、withValidator() が入りの早い順に並べ替えて比べて、1行目の入りにエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_compared_in_time_order(): void
    {
        $response = $this->postAsUser(['new_break_in' => ['12:30', '12:00'], 'new_break_out' => ['13:30', '13:00']]);

        $response->assertSessionHasErrors(['new_break_in.0' => '休憩時間が不適切な値です']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 休憩が 12:00〜15:00、13:00〜13:30、14:00〜14:30 なら、new_break_in.1 と new_break_in.2 に「休憩時間が不適切な値です」が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} でその3行の休憩を送り、withValidator() がそれまでで一番遅い戻りと比べて、2行目と3行目の入りにエラーが出て、申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_inside_long_break_are_rejected(): void
    {
        $response = $this->postAsUser([
            'new_break_in' => ['12:00', '13:00', '14:00'],
            'new_break_out' => ['15:00', '13:30', '14:30'],
        ]);

        $response->assertSessionHasErrors([
            'new_break_in.1' => '休憩時間が不適切な値です',
            'new_break_in.2' => '休憩時間が不適切な値です',
        ]);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 一般ユーザーが 2026-06-02 に、自分の 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00、申請なし）に修正の値を送る。条件に書いていない項目は、正しい値を送る。
     *
     * @param  array<string, mixed>  $overrides  正しい値から変える項目と値
     * @return TestResponse 送った後の応答
     */
    private function postAsUser(array $overrides): TestResponse
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user);

        return $this->actingAs($user)
            ->from('/attendance/'.$this->attendanceRecord->id)
            ->post('/attendance/'.$this->attendanceRecord->id, array_merge($this->validValues('在宅勤務のため'), $overrides));
    }

    /**
     * 管理者が 2026-06-02 に、一般ユーザーの 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00）に直した値を送る。条件に書いていない項目は、正しい値を送る。
     *
     * @param  array<string, mixed>  $overrides  正しい値から変える項目と値
     * @return TestResponse 送った後の応答
     */
    private function postAsAdmin(array $overrides): TestResponse
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->createAttendanceRecord($user);

        return $this->actingAs($admin)
            ->from('/attendance/'.$this->attendanceRecord->id)
            ->post('/attendance/'.$this->attendanceRecord->id, array_merge($this->validValues('打刻の時刻を誤ったため'), $overrides));
    }

    /**
     * 2026-06-02 に固定し、その人の 2026-06-01 の勤怠（09:00〜18:00、休憩 12:00〜13:00）を作る。
     *
     * @param  User  $user  勤怠の持ち主
     * @return void 返す値はない
     */
    private function createAttendanceRecord(User $user): void
    {
        $this->travelTo(Carbon::parse('2026-06-02 10:00:00'));
        $this->attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create([
            'attendance_record_id' => $this->attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
    }

    /**
     * 勤怠詳細の画面から送る正しい値（出勤 09:00、退勤 18:00、休憩1行 12:00〜13:00、追加用の両方空の行）を返す。
     *
     * @param  string  $comment  備考
     * @return array<string, mixed> 送る値
     */
    private function validValues(string $comment): array
    {
        return [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00', ''],
            'new_break_out' => ['13:00', ''],
            'comment' => $comment,
        ];
    }

    /**
     * 管理者の送った値が誤っていたので、勤怠が 09:00〜18:00 のまま変わっていないかを確かめる。
     *
     * @return void 返す値はない
     */
    private function assertAttendanceRecordIsUnchanged(): void
    {
        $this->assertDatabaseHas('attendance_records', [
            'id' => $this->attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
    }
}
