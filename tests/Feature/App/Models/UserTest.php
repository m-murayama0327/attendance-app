<?php

namespace Tests\Feature\App\Models;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class UserTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 会員登録をすると、登録したユーザーに VerifyEmail の通知が送られることを確かめる。
     * ログインしていない人が POST /register に送り、クラスの宣言の implements MustVerifyEmail で Laravel が登録の後に認証メールを送るようにして、登録したユーザーに VerifyEmail の通知が1通送られ、ユーザーが1行でき、email_verified_at が null で、/attendance に移り、ログインした状態になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_verification_mail_is_sent_after_register(): void
    {
        Notification::fake();

        $response = $this->post('/register', [
            'name' => '山田太郎',
            'email' => 'yamada@example.com',
            'password' => 'password',
            'password_confirmation' => 'password',
        ]);

        $user = User::where('email', 'yamada@example.com')->first();
        $response->assertRedirect('/attendance');
        Notification::assertSentToTimes($user, VerifyEmail::class, 1);
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    /**
     * fill() に admin_status を true で渡しても、admin_status が入らず、null のままであることを確かめる。
     * テストから新しい User の fill() に admin_status を true で渡し、$fillable が admin_status を受け付けず、admin_status が null のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_status_is_not_fillable(): void
    {
        $user = new User;

        $user->fill(['admin_status' => true]);

        $this->assertNull($user->admin_status);
    }

    /**
     * admin_status のデータベースの値が既定の 0 なら、bool の false で読めることを確かめる。
     * テストから admin_status を渡さずにファクトリーでユーザーを作って fresh() で読み直し、$casts が admin_status を真偽値にして、false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_status_is_cast_to_bool(): void
    {
        $user = User::factory()->create()->fresh();

        $this->assertFalse($user->admin_status);
    }

    /**
     * 自分の勤怠と他の人の勤怠が1行ずつあっても、自分の勤怠の id だけを返すことを確かめる。
     * テストから自分と他の人の勤怠を1行ずつ作って User の attendanceRecords を読み、attendanceRecords() が user_id で絞って、自分の勤怠の id だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_has_attendance_records(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id]);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id]);

        $this->assertSame([$attendanceRecord->id], $user->attendanceRecords->pluck('id')->all());
    }

    /**
     * 自分の申請と他の人の申請が1行ずつあっても、自分の申請の id だけを返すことを確かめる。
     * テストから自分と他の人の申請を1行ずつ作って User の applications を読み、applications() が user_id で絞って、自分の申請の id だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_has_applications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $application = Application::factory()->create(['user_id' => $user->id]);
        Application::factory()->create(['user_id' => $otherUser->id]);

        $this->assertSame([$application->id], $user->applications->pluck('id')->all());
    }

    /**
     * 管理者1人と一般ユーザー2人がいても、スタッフ一覧に管理者の名前が出ないことを確かめる。
     * 管理者が GET /admin/staff/list を開き、scopeGeneral() が管理者を除いて、200 で admin.staff-list に一般ユーザーの名前、メール、勤怠一覧のリンクが id の順で出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_general_users_are_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true, 'name' => '管理者太郎']);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎', 'email' => 'yamada@example.com']);
        $otherUser = User::factory()->create(['admin_status' => false, 'name' => '佐藤花子', 'email' => 'sato@example.com']);

        $response = $this->actingAs($admin)->get('/admin/staff/list');

        $response->assertOk();
        $response->assertViewIs('admin.staff-list');
        $response->assertDontSee('管理者太郎');
        $response->assertSeeInOrder([
            '山田太郎',
            'yamada@example.com',
            url('/admin/attendance/staff/'.$user->id),
            '佐藤花子',
            'sato@example.com',
            url('/admin/attendance/staff/'.$otherUser->id),
        ], false);
    }

    /**
     * URL の id が管理者なら、スタッフ別の勤怠一覧が 404 になることを確かめる。
     * 管理者が自分の id で GET /admin/attendance/staff/{id} を開き、scopeGeneral() が管理者を除いて、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_id_returns_not_found(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$admin->id);

        $response->assertNotFound();
    }

    /**
     * ユーザーの登録の順が名前の順と違っても、管理者の勤怠一覧に id の順で出ることを確かめる。
     * わたなべ、あおきの順に登録した2人の 2026-06-01 の勤怠があるときに、管理者が GET /admin/attendance/list を開き、scopeOrderByRegistration() が id の順に並べて、200 で admin.admin-attendance-list にわたなべ、あおきの順で出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_todays_records_are_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $firstUser = User::factory()->create(['admin_status' => false, 'name' => 'わたなべ']);
        $secondUser = User::factory()->create(['admin_status' => false, 'name' => 'あおき']);
        AttendanceRecord::factory()->create(['user_id' => $firstUser->id, 'date' => '2026-06-01']);
        AttendanceRecord::factory()->create(['user_id' => $secondUser->id, 'date' => '2026-06-01']);
        $this->travelTo(Carbon::parse('2026-06-01 20:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-attendance-list');
        $response->assertSeeInOrder(['わたなべ', 'あおき']);
    }

    /**
     * 他の人の今日の勤怠だけがあるなら、退勤を打っても、他の人の勤怠が変わらないことを確かめる。
     * 他の人の出勤中の今日の勤怠だけがあるときに、一般ユーザーが POST /attendance に action=clock_out を送り、findTodayAttendanceRecord() が自分の勤怠だけを探して、/attendance に移り、他の人の勤怠が1行のまま変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_ignores_other_user(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 12:00:00'));
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => null]);

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $otherUser->id, 'clock_in' => '09:00:00', 'clock_out' => null]);
    }

    /**
     * 他の人の今日の勤怠だけがあるなら、出勤を打つと、自分の勤怠ができることを確かめる。
     * 他の人の出勤中の今日の勤怠だけがあるときに、一般ユーザーが POST /attendance に action=clock_in を送り、findTodayAttendanceRecord() が自分の勤怠だけを探して、/attendance に移り、自分の 2026-06-01 の 10:00 出勤の勤怠ができ、他の人の勤怠が変わらないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_ignores_other_user(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => null]);

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_IN]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '10:00:00']);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $otherUser->id, 'clock_in' => '09:00:00', 'clock_out' => null]);
    }

    /**
     * 他の人の今日の勤怠だけがあるなら、打刻の画面に「勤務外」が出ることを確かめる。
     * 他の人の出勤中の今日の勤怠だけがあるときに、一般ユーザーが GET /attendance を開き、findTodayAttendanceRecord() が自分の勤怠だけを探して、200 で「勤務外」とボタン clock_in だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_users_record_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => null]);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('勤務外');
        $this->assertOnlyButtons($response, [AttendanceRecord::ACTION_CLOCK_IN]);
    }

    /**
     * 前の日の退勤なしの勤怠だけがあるなら、翌日に退勤を打っても、前の日の clock_out が空のままであることを確かめる。
     * 2026-05-31 の退勤なしの勤怠だけがあるときに、一般ユーザーが 2026-06-01 に POST /attendance に action=clock_out を送り、findTodayAttendanceRecord() が今日の勤怠を探して、/attendance に移り、勤怠が1行のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_next_day_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31', 'clock_in' => '09:00:00', 'clock_out' => null]);
        $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_OUT]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-05-31', 'clock_out' => null]);
    }

    /**
     * 前の日の退勤なしの勤怠だけがあるなら、翌日に出勤を打つと、翌日の勤怠ができることを確かめる。
     * 2026-05-31 の退勤なしの勤怠だけがあるときに、一般ユーザーが 2026-06-01 09:00 に POST /attendance に action=clock_in を送り、findTodayAttendanceRecord() が今日の勤怠を探して、/attendance に移り、新しい勤怠ができ、2026-05-31 の勤怠の clock_out が空のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_next_day_creates_new_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31', 'clock_in' => '09:00:00', 'clock_out' => null]);
        $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));

        $response = $this->actingAs($user)->post('/attendance', ['action' => AttendanceRecord::ACTION_CLOCK_IN]);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00']);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-05-31', 'clock_out' => null]);
    }

    /**
     * 今の日付が勤怠の日付と同じ日（23:59:59）なら、打刻の画面に「休憩中」が出ることを確かめる。
     * 2026-05-31 の勤怠に 23:59 からの戻りのない休憩があるときに、一般ユーザーが 23:59:59 に GET /attendance を開き、findTodayAttendanceRecord() がその勤怠を見つけて、200 でボタン break_out だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_day_open_record_is_used_before_midnight(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31', 'clock_in' => '09:00:00', 'clock_out' => null]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '23:59:00', 'break_out' => null]);
        $this->travelTo(Carbon::parse('2026-05-31 23:59:59'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('休憩中');
        $this->assertOnlyButtons($response, [AttendanceRecord::ACTION_BREAK_OUT]);
    }

    /**
     * 今の日付が勤怠の日付の翌日（00:00:00）なら、打刻の画面に「勤務外」が出ることを確かめる。
     * 2026-05-31 の勤怠に 23:59 からの戻りのない休憩があるときに、一般ユーザーが 2026-06-01 00:00:00 に GET /attendance を開き、findTodayAttendanceRecord() が今日の勤怠を探して、200 でボタン clock_in だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_previous_day_open_record_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31', 'clock_in' => '09:00:00', 'clock_out' => null]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '23:59:00', 'break_out' => null]);
        $this->travelTo(Carbon::parse('2026-06-01 00:00:00'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('勤務外');
        $this->assertOnlyButtons($response, [AttendanceRecord::ACTION_CLOCK_IN]);
    }

    /**
     * 今日の勤怠がないなら、打刻の画面に「勤務外」が出ることを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが 2026-06-01 08:00 に GET /attendance を開き、resolveAttendanceStatus() が「勤務外」を返して、200 でボタン clock_in だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_status_off_duty(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 08:00:00'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('勤務外');
        $this->assertOnlyButtons($response, [AttendanceRecord::ACTION_CLOCK_IN]);
    }

    /**
     * 退勤も休憩もない勤怠なら、打刻の画面に「出勤中」が出ることを確かめる。
     * 09:00 出勤で退勤も休憩もない今日の勤怠があるときに、一般ユーザーが GET /attendance を開き、resolveAttendanceStatus() が「出勤中」を返して、200 でボタン clock_out と break_in だけが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_status_working(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => null]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('出勤中');
        $this->assertOnlyButtons($response, [AttendanceRecord::ACTION_CLOCK_OUT, AttendanceRecord::ACTION_BREAK_IN]);
    }

    /**
     * 退勤がある勤怠なら、打刻の画面に「退勤済」と「お疲れ様でした。」が出ることを確かめる。
     * 2026-06-01 の 09:00〜18:00 の勤怠があるときに、一般ユーザーが 19:00 に GET /attendance を開き、resolveAttendanceStatus() が「退勤済」を返して、200 でボタンが出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_status_finished(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        $this->travelTo(Carbon::parse('2026-06-01 19:00:00'));

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertOk();
        $response->assertSee('退勤済');
        $response->assertSee('お疲れ様でした。');
        $this->assertOnlyButtons($response, []);
    }

    /**
     * ログインする人が一般ユーザーなら、/attendance に移ることを確かめる。
     * ログインしていない人が POST /login に一般ユーザーの test@example.com と password を送り、homePath() が /attendance を返して、そこに移り、そのユーザーでログインするかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_redirects_to_attendance(): void
    {
        $user = User::factory()->create(['admin_status' => false, 'email' => 'test@example.com']);

        $response = $this->post('/login', ['email' => 'test@example.com', 'password' => 'password']);

        $response->assertRedirect('/attendance');
        $this->assertAuthenticatedAs($user);
    }

    /**
     * ログインする人が管理者なら、/admin/attendance/list に移ることを確かめる。
     * ログインしていない人が POST /admin/login に管理者の admin@example.com と password を送り、homePath() が /admin/attendance/list を返して、そこに移り、その管理者でログインするかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_redirects_to_admin_list(): void
    {
        $admin = User::factory()->create(['admin_status' => true, 'email' => 'admin@example.com']);

        $response = $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'password']);

        $response->assertRedirect('/admin/attendance/list');
        $this->assertAuthenticatedAs($admin);
    }

    /**
     * 打刻の画面に、渡した打刻の種類のボタンだけが出て、他の打刻の種類のボタンが出ないことを確かめる。
     *
     * @param  TestResponse  $response  打刻の画面を開いた応答
     * @param  array<int, string>  $actions  出るはずのボタンの打刻の種類（AttendanceRecord の ACTION_ の定数）
     * @return void 返す値はない
     */
    private function assertOnlyButtons(TestResponse $response, array $actions): void
    {
        collect(AttendanceRecord::ACTIONS)->each(fn (string $action): TestResponse => in_array($action, $actions, true)
            ? $response->assertSee('value="'.$action.'"', false)
            : $response->assertDontSee('value="'.$action.'"', false));
    }
}
