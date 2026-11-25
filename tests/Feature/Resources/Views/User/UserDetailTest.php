<?php

namespace Tests\Feature\Resources\Views\User;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開いた勤怠が保存した勤怠（自分の 2026-06-01）なら、フォームの送り先が /attendance/{id} になることを確かめる。
     * 自分の 2026-06-01 の勤怠があるときに、一般ユーザーが GET /attendance/{id} を送り、user-detail.blade.php が $data['id'] があるかで action を分けて、200 で user.user-detail のフォームの送り先が /attendance/{id} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_form_posts_to_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('action="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * 開いた勤怠が勤怠がない日の空の勤怠（自分の 2026-06-02）なら、フォームの送り先が /attendance/date/2026-06-02 になることを確かめる。
     * 自分の 2026-06-02 の勤怠がないときに、一般ユーザーが 2026-06-03 に GET /attendance/date/2026-06-02 を送り、user-detail.blade.php が $data['id'] があるかで action を分けて、200 で user.user-detail のフォームの送り先が同じ URL かを見る。
     *
     * @return void 返す値はない
     */
    public function test_form_posts_to_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/date/2026-06-02');

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('action="'.url('/attendance/date/2026-06-02').'"', false);
    }

    /**
     * 申請の is_approved が false なら、「承認待ちのため修正はできません。」が出て「承認済みの申請です。」が出ないことを確かめる。
     * 自分の 2026-06-01 の承認待ちの申請があるときに、一般ユーザーが GET /application/{id} を送り、user-detail.blade.php が is_approved で文を分けて、200 で「承認待ちのため修正はできません。」が出て、「承認済みの申請です。」が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_application_message_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertOk();
        $response->assertSee('承認待ちのため修正はできません。');
        $response->assertDontSee('承認済みの申請です。');
    }

    /**
     * 申請の is_approved が true なら、「承認済みの申請です。」が出て「承認待ちのため修正はできません。」が出ないことを確かめる。
     * 自分の 2026-06-01 の承認済みの申請があるときに、一般ユーザーが GET /application/{id} を送り、user-detail.blade.php が is_approved で文を分けて、200 で「承認済みの申請です。」が出て、「承認待ちのため修正はできません。」が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_application_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'is_approved' => true]);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertOk();
        $response->assertSee('承認済みの申請です。');
        $response->assertDontSee('承認待ちのため修正はできません。');
    }

    /**
     * 勤怠の休憩が1行（12:00〜13:00）なら、「〜」の数が出勤・退勤の行と休憩の行の2になることを確かめる。
     * 自分の 2026-06-01 の勤怠の休憩が1行のときに、一般ユーザーが GET /attendance/{id} を送り、user-detail.blade.php が追加用の空の休憩の行に「〜」を出さずに、200 で「〜」の数が2かを見る。
     *
     * @return void 返す値はない
     */
    public function test_tilde_is_not_shown_in_empty_break_row(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), '〜'));
    }

    /**
     * 保存した備考は、直せる備考の欄の中身に出ることを確かめる。
     * 自分の 2026-06-01 の勤怠の備考が「在宅勤務のため」のときに、一般ユーザーが GET /attendance/{id} を送り、user-detail.blade.php が備考を textarea の中身に置いて、200 で直せる備考の欄の中身が「在宅勤務のため」かを見る。
     *
     * @return void 返す値はない
     */
    public function test_saved_comment_is_shown_in_textarea(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '在宅勤務のため']);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertSee('<textarea class="form__textarea" id="comment" name="comment">在宅勤務のため</textarea>', false);
    }

    /**
     * 申請の備考は、読み取りの備考の欄の中身に出ることを確かめる。
     * 自分の 2026-06-01 の承認待ちの申請の備考が「打刻の時刻を誤ったため」のときに、一般ユーザーが GET /application/{id} を送り、user-detail.blade.php が申請の備考を読み取りの textarea の中身に置いて、200 で読み取りの備考の欄の中身が「打刻の時刻を誤ったため」かを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_comment_is_shown_in_readonly_textarea(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '打刻の時刻を誤ったため']);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertOk();
        $response->assertSee('<textarea class="form__textarea readonly" readonly>打刻の時刻を誤ったため</textarea>', false);
    }
}
