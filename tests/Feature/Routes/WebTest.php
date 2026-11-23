<?php

namespace Tests\Feature\Routes;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WebTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く人が一般ユーザーなら、GET /admin/login で /attendance に移ることを確かめる。
     * 一般ユーザーが GET /admin/login を送り、ルートの行が guest:web でログインしている人を止めて、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_redirects_from_admin_login(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/login');

        $response->assertRedirect('/attendance');
    }

    /**
     * 同じメールと IP で送った回数が5回なら、6回目が 429 になることを確かめる。
     * 管理者（admin@example.com）がいるときに、ログインしていない人が違うパスワードで POST /admin/login を6回送り、ルートの行が throttle:login で回数を数えて、5回目は /admin/login に戻り6回目が 429 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_login_is_rate_limited(): void
    {
        User::factory()->create(['admin_status' => true, 'email' => 'admin@example.com']);
        Collection::times(4, fn (): TestResponse => $this->from('/admin/login')->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'wrongpass',
        ]));

        $fifthResponse = $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrongpass']);
        $sixthResponse = $this->from('/admin/login')->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrongpass']);

        $fifthResponse->assertRedirect('/admin/login');
        $sixthResponse->assertStatus(429);
    }

    /**
     * 送る人がログインしている管理者なら、違うパスワードでも /admin/attendance/list に移ることを確かめる。
     * ログインしている管理者が POST /admin/login に登録したメールと違うパスワードを送り、ルートの行が guest:web でログインしている人を止めて、/admin/attendance/list に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_redirects_from_admin_login_post(): void
    {
        $admin = User::factory()->create(['admin_status' => true, 'email' => 'admin@example.com', 'password' => 'password']);

        $response = $this->actingAs($admin)->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrongpass']);

        $response->assertRedirect('/admin/attendance/list');
    }

    /**
     * 送る人が一般ユーザーなら、POST /admin/logout を送ってもログインしたままになることを確かめる。
     * 一般ユーザーが POST /admin/logout を送り、ルートの行が admin で一般ユーザーを止めて、403 を返しログインしたままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_stays_logged_in_on_admin_logout(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/admin/logout');

        $response->assertForbidden();
        $this->assertAuthenticatedAs($user);
    }

    /**
     * 開く人が一般ユーザーなら、GET /admin/attendance/list が 403 になることを確かめる。
     * 一般ユーザーが GET /admin/attendance/list を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_admin_attendance_list(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/attendance/list');

        $response->assertForbidden();
    }

    /**
     * 開く人が一般ユーザーなら、GET /admin/attendance/staff/{id} が 403 になることを確かめる。
     * 一般ユーザーが自分の id で GET /admin/attendance/staff/{id} を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_staff_attendance(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/attendance/staff/'.$user->id);

        $response->assertForbidden();
    }

    /**
     * URL の id が数字でない（abc）なら、GET /admin/attendance/staff/abc が 404 になることを確かめる。
     * 管理者が GET /admin/attendance/staff/abc を送り、ルートの行が whereNumber('id') で数字でない id をルートに当てずに、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_non_numeric_staff_id_returns_not_found(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/abc');

        $response->assertNotFound();
    }

    /**
     * 開く人が一般ユーザーなら、GET /admin/attendance/staff/{id}/date/2026-06-02 が 403 になることを確かめる。
     * 一般ユーザーが自分の id で GET /admin/attendance/staff/{id}/date/2026-06-02 を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_on_staff_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertForbidden();
    }

    /**
     * URL の id が数字でない（abc）なら、GET /admin/attendance/staff/abc/date/2026-06-02 が 404 になることを確かめる。
     * 管理者が GET /admin/attendance/staff/abc/date/2026-06-02 を送り、ルートの行が whereNumber('id') で数字でない id をルートに当てずに、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_non_numeric_staff_id_returns_not_found_on_staff_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/abc/date/2026-06-02');

        $response->assertNotFound();
    }

    /**
     * 送る人が一般ユーザーなら、POST /admin/attendance/staff/{id}/date/2026-06-02 を送っても勤怠ができないことを確かめる。
     * 一般ユーザーが POST /admin/attendance/staff/{id}/date/2026-06-02 に出勤 09:00 と退勤 18:00 などを送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返し勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_cannot_create_on_staff_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/admin/attendance/staff/'.$user->id.'/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * URL の id が数字でない（abc）なら、POST /admin/attendance/staff/abc/date/2026-06-02 が 404 になることを確かめる。
     * 管理者が POST /admin/attendance/staff/abc/date/2026-06-02 に出勤 09:00 と退勤 18:00 などを送り、ルートの行が whereNumber('id') で数字でない id をルートに当てずに、404 を返し勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_non_numeric_staff_id_returns_not_found_on_staff_date_post(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->post('/admin/attendance/staff/abc/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 開いた番号の勤怠がない（999）なら、GET /admin/attendance/999 が 404 になることを確かめる。
     * 番号 999 の勤怠がないときに、管理者が GET /admin/attendance/999 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_admin_attendance(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/attendance/999');

        $response->assertNotFound();
    }

    /**
     * 開く人が一般ユーザーなら、自分の勤怠でも GET /admin/attendance/{id} が 403 になることを確かめる。
     * 自分の 2026-06-01 の勤怠があるときに、一般ユーザーが GET /admin/attendance/{id} を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_admin_attendance_detail(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/admin/attendance/'.$attendanceRecord->id);

        $response->assertForbidden();
    }

    /**
     * 開いた番号の勤怠があるなら、/attendance/{id} と同じ管理者の勤怠詳細の画面にその勤怠の値が出ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠（09:00〜18:00）があるときに、管理者が GET /admin/attendance/{id} を送り、ルートの行がルートモデルバインディングで勤怠を探して、200 で admin.admin-detail に 09:00 と 18:00 が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_attendance_route_shows_admin_detail(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);

        $response = $this->actingAs($admin)->get('/admin/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('value="09:00"', false);
        $response->assertSee('value="18:00"', false);
    }

    /**
     * 開く人が一般ユーザーで URL の attendanceRecord が数字でない（abc）なら、GET /admin/attendance/abc が 404 になることを確かめる。
     * 一般ユーザーが GET /admin/attendance/abc を送り、ルートの行が whereNumber('attendanceRecord') で数字でない番号をルートに当てずに、admin のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_not_found_for_non_numeric_admin_attendance(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/attendance/abc');

        $response->assertNotFound();
    }

    /**
     * 開く人が一般ユーザーなら、GET /admin/staff/list が 403 になることを確かめる。
     * 一般ユーザーが GET /admin/staff/list を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_staff_list(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/admin/staff/list');

        $response->assertForbidden();
    }

    /**
     * 開く人が一般ユーザーなら、自分の申請でも GET /stamp_correction_request/approve/{id} が 403 になることを確かめる。
     * 自分の 2026-06-01 の承認待ちの申請があるときに、一般ユーザーが GET /stamp_correction_request/approve/{id} を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_forbidden_admin_application_detail(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/stamp_correction_request/approve/'.$application->id);

        $response->assertForbidden();
    }

    /**
     * 開く人が一般ユーザーで URL の attendance_correct_request_id が数字でない（abc）なら、GET /stamp_correction_request/approve/abc が 404 になることを確かめる。
     * 一般ユーザーが GET /stamp_correction_request/approve/abc を送り、ルートの行が whereNumber('attendance_correct_request_id') で数字でない番号をルートに当てずに、admin のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_not_found_for_non_numeric_approve(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/stamp_correction_request/approve/abc');

        $response->assertNotFound();
    }

    /**
     * 送る人が一般ユーザーなら、自分の承認待ちの申請に POST /stamp_correction_request/approve/{id} を送っても、申請が承認待ちのままで勤怠が変わらないことを確かめる。
     * 自分の勤怠（09:00〜18:00）への承認待ちの申請（08:30〜18:30）があるときに、一般ユーザーが POST /stamp_correction_request/approve/{id} を送り、ルートの行がグループに付けた admin で一般ユーザーを止めて、403 を返し申請と勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_cannot_approve(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'new_clock_in' => '08:30:00', 'new_clock_out' => '18:30:00']);

        $response = $this->actingAs($user)->post('/stamp_correction_request/approve/'.$application->id);

        $response->assertForbidden();
        $this->assertDatabaseHas('applications', ['id' => $application->id, 'is_approved' => false]);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
    }

    /**
     * 送る人が一般ユーザーで URL の attendance_correct_request_id が数字でない（abc）なら、POST /stamp_correction_request/approve/abc が 404 になることを確かめる。
     * 一般ユーザーが POST /stamp_correction_request/approve/abc を送り、ルートの行が whereNumber('attendance_correct_request_id') で数字でない番号をルートに当てずに、admin のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_gets_not_found_for_non_numeric_approve_post(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/stamp_correction_request/approve/abc');

        $response->assertNotFound();
    }

    /**
     * 開く人がログインしていない人なら、GET /attendance で /login に移ることを確かめる。
     * ログインしていない人が GET /attendance を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_attendance_page(): void
    {
        $response = $this->get('/attendance');

        $response->assertRedirect('/login');
    }

    /**
     * 開く人が管理者なら、GET /attendance が 403 になることを確かめる。
     * 管理者が GET /attendance を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_cannot_open_attendance_page(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance');

        $response->assertForbidden();
    }

    /**
     * 送る人がログインしていない人なら、POST /attendance に action=clock_in を送っても勤怠ができないことを確かめる。
     * ログインしていない人が POST /attendance に action=clock_in を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移り勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_stamp(): void
    {
        $response = $this->post('/attendance', ['action' => 'clock_in']);

        $response->assertRedirect('/login');
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 送る人が管理者なら、POST /attendance に action=clock_in を送っても勤怠ができないことを確かめる。
     * 管理者が POST /attendance に action=clock_in を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返し勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_cannot_stamp(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->post('/attendance', ['action' => 'clock_in']);

        $response->assertForbidden();
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 開く人がログインしていない人なら、GET /attendance/list で /login に移ることを確かめる。
     * ログインしていない人が GET /attendance/list を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_attendance_list(): void
    {
        $response = $this->get('/attendance/list');

        $response->assertRedirect('/login');
    }

    /**
     * 開く人が管理者なら、GET /attendance/list が 403 になることを確かめる。
     * 管理者が GET /attendance/list を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_attendance_list(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance/list');

        $response->assertForbidden();
    }

    /**
     * 開いた番号の勤怠がない（999）なら、GET /attendance/detail/999 が 404 になることを確かめる。
     * 番号 999 の勤怠がないときに、一般ユーザーが GET /attendance/detail/999 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_alias(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance/detail/999');

        $response->assertNotFound();
    }

    /**
     * 開く人がログインしていない人なら、GET /attendance/detail/{id} で /login に移ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、ログインしていない人が GET /attendance/detail/{id} を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_on_alias(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->get('/attendance/detail/'.$attendanceRecord->id);

        $response->assertRedirect('/login');
    }

    /**
     * 開く人が管理者なら、GET /attendance/detail/{id} が 403 になることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、管理者が GET /attendance/detail/{id} を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_on_alias(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($admin)->get('/attendance/detail/'.$attendanceRecord->id);

        $response->assertForbidden();
    }

    /**
     * 開いた番号の勤怠があるなら、GET /attendance/{id} と同じ勤怠詳細の画面にその勤怠の値が出ることを確かめる。
     * 自分の 2026-06-01 の勤怠（09:00〜18:00）があるときに、一般ユーザーが GET /attendance/detail/{id} を送り、ルートの行がルートモデルバインディングで勤怠を探して、200 で user.user-detail に 09:00 と 18:00 が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_alias_route_shows_same_page(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);

        $response = $this->actingAs($user)->get('/attendance/detail/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('value="09:00"', false);
        $response->assertSee('value="18:00"', false);
    }

    /**
     * 開く人が管理者で URL の attendanceRecord が数字でない（abc）なら、GET /attendance/detail/abc が 404 になることを確かめる。
     * 管理者が GET /attendance/detail/abc を送り、ルートの行が whereNumber('attendanceRecord') で数字でない番号をルートに当てずに、general のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_not_found_for_non_numeric_alias(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance/detail/abc');

        $response->assertNotFound();
    }

    /**
     * 開く人がログインしていない人なら、GET /attendance/date/2026-06-02 で /login に移ることを確かめる。
     * ログインしていない人が GET /attendance/date/2026-06-02 を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_on_date(): void
    {
        $response = $this->get('/attendance/date/2026-06-02');

        $response->assertRedirect('/login');
    }

    /**
     * 開く人が管理者なら、GET /attendance/date/2026-06-02 が 403 になることを確かめる。
     * 管理者が GET /attendance/date/2026-06-02 を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_on_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/attendance/date/2026-06-02');

        $response->assertForbidden();
    }

    /**
     * 送る人がログインしていない人なら、POST /attendance/date/2026-06-02 を送っても申請ができないことを確かめる。
     * ログインしていない人が POST /attendance/date/2026-06-02 に出勤 09:00 と退勤 18:00 などを送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移り申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_submit_on_date(): void
    {
        $response = $this->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人が管理者なら、POST /attendance/date/2026-06-02 を送っても申請ができないことを確かめる。
     * 管理者が POST /attendance/date/2026-06-02 に出勤 09:00 と退勤 18:00 などを送り、ルートの行がグループに付けた general で管理者を止めて、403 を返し申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_cannot_submit_on_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 開いた番号の申請がない（999）なら、GET /application/999 が 404 になることを確かめる。
     * 番号 999 の申請がないときに、一般ユーザーが GET /application/999 を送り、ルートの行がルートモデルバインディングで申請を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_application_returns_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/application/999');

        $response->assertNotFound();
    }

    /**
     * 開く人がログインしていない人なら、GET /application/{id} で /login に移ることを確かめる。
     * 一般ユーザーの 2026-06-01 の申請があるときに、ログインしていない人が GET /application/{id} を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_application_detail(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->get('/application/'.$application->id);

        $response->assertRedirect('/login');
    }

    /**
     * 開く人が管理者なら、GET /application/{id} が 403 になることを確かめる。
     * 一般ユーザーの 2026-06-01 の申請があるときに、管理者が GET /application/{id} を送り、ルートの行がグループに付けた general で管理者を止めて、403 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_forbidden_application_detail(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($admin)->get('/application/'.$application->id);

        $response->assertForbidden();
    }

    /**
     * 開く人が管理者で URL の application が数字でない（abc）なら、GET /application/abc が 404 になることを確かめる。
     * 管理者が GET /application/abc を送り、ルートの行が whereNumber('application') で数字でない番号をルートに当てずに、general のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_gets_not_found_for_non_numeric_application(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/application/abc');

        $response->assertNotFound();
    }

    /**
     * 開く人が管理者で渡す組が勤怠の show なら、管理者の勤怠詳細の画面（admin.admin-detail）が出ることを確かめる。
     * 一般ユーザー「山田太郎」の 2026-06-01 の勤怠があるときに、管理者が GET /attendance/{id} を送り、$callByRole が管理者のコントローラーの show() を呼んで、200 で admin.admin-detail に持ち主の名前「山田太郎」、日付の 2026年と 6月1日、出勤・退勤の 09:00 と 18:00、休憩の 12:00 と 13:00、備考「在宅勤務のため」、送り先の /attendance/{id} が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_values_are_shown_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '在宅勤務のため',
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($admin)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('value="山田太郎"', false);
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="6月1日"', false);
        $response->assertSee('value="09:00"', false);
        $response->assertSee('value="18:00"', false);
        $response->assertSee('value="12:00"', false);
        $response->assertSee('value="13:00"', false);
        $response->assertSee('在宅勤務のため');
        $response->assertSee('action="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * 開く人が管理者で渡す組が勤怠の update なら、勤怠の clock_in が 08:30:00 に変わり申請ができないことを確かめる。
     * 一般ユーザーの勤怠（09:00〜18:00）があるときに、管理者が POST /attendance/{id} に出勤 08:30 と退勤 18:00 などを送り、$callByRole が管理者のコントローラーの update() を呼んで、clock_in が 08:30:00 に変わり申請ができず、/attendance/{id} に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_updates_record_directly_on_shared_route(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);

        $response = $this->actingAs($admin)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'clock_in' => '08:30:00']);
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 開く人が一般ユーザーで渡す組が勤怠の show なら、一般ユーザーの勤怠詳細の画面（user.user-detail）が出ることを確かめる。
     * 自分の 2026-06-01 の勤怠があるときに、一般ユーザーが GET /attendance/{id} を送り、$callByRole が一般ユーザーのコントローラーの show() を呼んで、200 で user.user-detail が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_opens_user_detail_on_shared_route(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
    }

    /**
     * 開く人が管理者で渡す組が申請の index なら、管理者の申請一覧の画面（admin.admin-application-list）が出ることを確かめる。
     * 管理者が GET /stamp_correction_request/list を送り、$callByRole が管理者のコントローラーの index() を呼んで、200 で admin.admin-application-list が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_opens_admin_application_list_on_shared_route(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-list');
    }

    /**
     * 開いた番号の勤怠がない（999）なら、GET /attendance/999 が 404 になることを確かめる。
     * 番号 999 の勤怠がないときに、一般ユーザーが GET /attendance/999 を送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance/999');

        $response->assertNotFound();
    }

    /**
     * 開く人がログインしていない人なら、GET /attendance/{id} で /login に移ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、ログインしていない人が GET /attendance/{id} を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_attendance_detail(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->get('/attendance/'.$attendanceRecord->id);

        $response->assertRedirect('/login');
    }

    /**
     * 開く人がログインしていない人で URL の attendanceRecord が数字でない（abc）なら、GET /attendance/abc が 404 になることを確かめる。
     * ログインしていない人が GET /attendance/abc を送り、ルートの行が whereNumber('attendanceRecord') で数字でない番号をルートに当てずに、auth のミドルウェアより先に止めて、404 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_gets_not_found_for_non_numeric_attendance(): void
    {
        $response = $this->get('/attendance/abc');

        $response->assertNotFound();
    }

    /**
     * 送った番号の勤怠がない（999）なら、POST /attendance/999 が 404 になることを確かめる。
     * 番号 999 の勤怠がないときに、一般ユーザーが POST /attendance/999 に出勤 09:00 と退勤 18:00 などを送り、ルートの行がルートモデルバインディングで勤怠を探して、404 を返し申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_record_returns_not_found_on_update(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->post('/attendance/999', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人がログインしていない人なら、POST /attendance/{id} を送っても申請ができないことを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、ログインしていない人が POST /attendance/{id} に出勤 09:00 と退勤 18:00 などを送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移り申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_cannot_apply(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertRedirect('/login');
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 送る人がログインしていない人で URL の attendanceRecord が数字でない（abc）なら、POST /attendance/abc が 404 になることを確かめる。
     * ログインしていない人が POST /attendance/abc に出勤 09:00 と退勤 18:00 などを送り、ルートの行が whereNumber('attendanceRecord') で数字でない番号をルートに当てずに、auth のミドルウェアより先に止めて、404 を返し申請ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_gets_not_found_for_non_numeric_attendance_post(): void
    {
        $response = $this->post('/attendance/abc', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseCount('applications', 0);
    }

    /**
     * 開く人がログインしていない人なら、GET /stamp_correction_request/list で /login に移ることを確かめる。
     * ログインしていない人が GET /stamp_correction_request/list を送り、ルートの行がグループに付けた auth でログインしていない人を止めて、/login に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_guest_redirects_to_login_application_list(): void
    {
        $response = $this->get('/stamp_correction_request/list');

        $response->assertRedirect('/login');
    }
}
