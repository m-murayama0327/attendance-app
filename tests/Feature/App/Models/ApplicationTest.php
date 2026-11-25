<?php

namespace Tests\Feature\App\Models;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\ProposalBreak;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

class ApplicationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * fill() に is_approved を true で渡しても、is_approved が null のままであることを確かめる。
     * テストから Application の fill() に is_approved を true で渡し、$fillable が is_approved を受け付けず、is_approved が null のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_is_approved_is_not_fillable(): void
    {
        $application = new Application;

        $application->fill(['is_approved' => true]);

        $this->assertNull($application->is_approved);
    }

    /**
     * 保存した is_approved が既定の 0 なら、bool の false で読めることを確かめる。
     * テストから is_approved を渡さずに申請を作って読み直し、$casts が is_approved を真偽値にして、false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_is_approved_is_cast_to_bool(): void
    {
        $application = Application::factory()->create();

        $this->assertFalse($application->fresh()->is_approved);
    }

    /**
     * 申請の user_id が申請した人の id なら、user() がそのユーザーを返すことを確かめる。
     * テストから申請を作って Application の user を読み、user() が申請の user_id でユーザーを探して、申請した人を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $application = Application::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($application->user->is($user));
    }

    /**
     * 申請の日（2026-06-01）の勤怠がないなら、findAttendanceRecord() が null を返すことを確かめる。
     * 勤怠がないときに、テストから 2026-06-01 の申請の findAttendanceRecord() を呼び、同じ人と日付の勤怠を探して、null を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_find_attendance_record_returns_null_without_record(): void
    {
        $application = Application::factory()->create(['date' => '2026-06-01']);

        $this->assertNull($application->findAttendanceRecord());
    }

    /**
     * 他の人の同じ日の勤怠を先に作っても、同じ人と日付の勤怠を返すことを確かめる。
     * 他の人と同じ人の 2026-06-01 の勤怠があるときに、テストから findAttendanceRecord() を呼び、同じ人と日付で勤怠を探して、同じ人の勤怠を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_finds_same_day_record(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertTrue($application->findAttendanceRecord()->is($attendanceRecord));
    }

    /**
     * 同じ人の他の日（2026-05-31）の勤怠だけがあるなら、findAttendanceRecord() が null を返すことを確かめる。
     * 同じ人の 2026-05-31 の勤怠だけがあるときに、テストから 2026-06-01 の申請の findAttendanceRecord() を呼び、同じ人と日付で勤怠を探して、null を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_find_attendance_record_ignores_other_day_record(): void
    {
        $user = User::factory()->create();
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertNull($application->findAttendanceRecord());
    }

    /**
     * 申請の休憩を入りの遅い順に作っても、break_in が 12:00:00、15:00:00 の順で返ることを確かめる。
     * 申請の休憩を 15:00〜15:15、12:00〜13:00 の順に作ったときに、テストから proposalBreaks を読み、proposalBreaks() が break_in の昇順に並べるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_proposal_breaks_are_sorted(): void
    {
        $application = Application::factory()->create();
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '15:00:00', 'break_out' => '15:15:00']);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $this->assertSame(['12:00:00', '15:00:00'], $application->proposalBreaks->pluck('break_in')->all());
    }

    /**
     * 他の申請の休憩があっても、1つ目の申請の行だけを返すことを確かめる。
     * 2つの申請に申請の休憩が1行ずつあるときに、テストから1つ目の申請の proposalBreaks を読み、proposalBreaks() が application_id で絞って、1つ目の申請の行だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_has_proposal_breaks(): void
    {
        $application = Application::factory()->create();
        $otherApplication = Application::factory()->create();
        $proposalBreak = ProposalBreak::factory()->create(['application_id' => $application->id]);
        ProposalBreak::factory()->create(['application_id' => $otherApplication->id]);

        $this->assertSame([$proposalBreak->id], $application->proposalBreaks->pluck('id')->all());
    }

    /**
     * 申請の created_at が違うなら、2026-06-03、2026-06-02、2026-06-01 の順で返すことを確かめる。
     * created_at が 2026-06-01、2026-06-03、2026-06-02 の順に申請を作ったときに、テストから latestApplied() で読み、scopeLatestApplied() が新しい順に並べるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_list_rows_are_sorted(): void
    {
        $firstApplication = Application::factory()->create(['created_at' => '2026-06-01 10:00:00']);
        $thirdApplication = Application::factory()->create(['created_at' => '2026-06-03 10:00:00']);
        $secondApplication = Application::factory()->create(['created_at' => '2026-06-02 10:00:00']);

        $this->assertSame(
            [$thirdApplication->id, $secondApplication->id, $firstApplication->id],
            Application::latestApplied()->pluck('id')->all(),
        );
    }

    /**
     * 申請の created_at が同じなら、id の大きい順で返すことを確かめる。
     * created_at が 2026-06-02 10:00 の申請が2行あるときに、テストから latestApplied() で読み、scopeLatestApplied() が id の大きい順に並べるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_time_applications_are_sorted_by_id(): void
    {
        $firstApplication = Application::factory()->create(['created_at' => '2026-06-02 10:00:00']);
        $secondApplication = Application::factory()->create(['created_at' => '2026-06-02 10:00:00']);

        $this->assertSame([$secondApplication->id, $firstApplication->id], Application::latestApplied()->pluck('id')->all());
    }

    /**
     * その人の申請がないなら、申請一覧に申請の行が1つも出ないことを確かめる。
     * 申請がないときに、一般ユーザーが GET /stamp_correction_request/list を開き、listRowsFor() がその人の申請を探して、200 で申請の行が1つも出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_no_rows_are_shown_without_applications(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertDontSee('table__description', false);
    }

    /**
     * 自分と他の人の承認待ちの申請があるなら、承認待ちの表に自分の申請だけが出ることを確かめる。
     * 自分と他の人の承認待ちの申請があるときに、一般ユーザーが GET /stamp_correction_request/list を開き、listRowsFor() がその人の申請だけを選んで、200 で user.user-application-list に「承認待ち」と自分の申請が出て、他の人の申請が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_applications_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        Application::factory()->create(['user_id' => $user->id, 'comment' => '自分の申請']);
        Application::factory()->create(['user_id' => $otherUser->id, 'comment' => '他の人の申請']);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('user.user-application-list');
        $pendingTable = Str::between($response->getContent(), 'id="content1"', 'id="content2"');
        $this->assertStringContainsString('承認待ち', $pendingTable);
        $this->assertStringContainsString('自分の申請', $pendingTable);
        $response->assertDontSee('他の人の申請');
    }

    /**
     * 申請の日の勤怠がないなら、その申請の行に申請する日の「2026/06/01」が出ることを確かめる。
     * 勤怠がない 2026-06-01 の申請があるときに、一般ユーザーが GET /stamp_correction_request/list を開き、listRowsFor() が申請の date で日付を作って、200 で「2026/06/01」と名前が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_for_day_without_record_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertSee('2026/06/01');
        $response->assertSee('山田太郎');
    }

    /**
     * 申請の created_at の日が date と違うなら、申請日に created_at の「2026/06/02」が出ることを確かめる。
     * date 2026-06-01、created_at 2026-06-02 の申請があるときに、一般ユーザーが GET /stamp_correction_request/list を開き、listRowsFor() が行を作って、名前、対象日、備考、申請日、リンクの順に出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_row_values_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'comment' => '打刻の時刻を誤ったため',
            'created_at' => '2026-06-02 10:00:00',
        ]);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertSeeInOrder(['山田太郎', '2026/06/01', '打刻の時刻を誤ったため', '2026/06/02', url('/application/'.$application->id)], false);
    }

    /**
     * 申請がないなら、管理者の申請一覧に申請の行が1つも出ないことを確かめる。
     * 申請がないときに、管理者が GET /stamp_correction_request/list を開き、allForAdminList() が全員の申請を探して、200 で申請の行が1つも出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_no_rows_are_shown_without_applications_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertDontSee('table__description', false);
    }

    /**
     * 2人の一般ユーザーの承認待ちと承認済みの申請があるなら、4つの申請が承認の状態ごとの表に出ることを確かめる。
     * 2人の一般ユーザーに承認待ちと承認済みの申請が1つずつあるときに、管理者が GET /stamp_correction_request/list を開き、allForAdminList() が全員の申請を返して、200 で admin.admin-application-list を出し、各表に他の状態の文字が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_all_applications_are_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        Application::factory()->create(['user_id' => $user->id, 'comment' => '1人目の承認待ち']);
        Application::factory()->create(['user_id' => $user->id, 'comment' => '1人目の承認済み', 'is_approved' => true]);
        Application::factory()->create(['user_id' => $otherUser->id, 'comment' => '2人目の承認待ち']);
        Application::factory()->create(['user_id' => $otherUser->id, 'comment' => '2人目の承認済み', 'is_approved' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-list');
        $pendingTable = Str::between($response->getContent(), 'id="content1"', 'id="content2"');
        $approvedTable = Str::after($response->getContent(), 'id="content2"');
        $this->assertStringContainsString('1人目の承認待ち', $pendingTable);
        $this->assertStringContainsString('2人目の承認待ち', $pendingTable);
        $this->assertStringContainsString('1人目の承認済み', $approvedTable);
        $this->assertStringContainsString('2人目の承認済み', $approvedTable);
        $this->assertStringNotContainsString('承認済み', $pendingTable);
        $this->assertStringNotContainsString('承認待ち', $approvedTable);
    }

    /**
     * 同じ日の勤怠と違う値の申請なら、申請詳細に申請の値が出て、勤怠の値が出ないことを確かめる。
     * 勤怠（09:00〜18:00、休憩 12:00〜13:00）があるときに、一般ユーザーが自分の承認待ちの申請を GET /application/{id} で開き、toDetailData() が申請の値を渡して、200 で user.user-detail に名前、日付、承認待ちの文、送り先が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_application_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:30:00', 'break_out' => '13:30:00']);

        $response = $this->actingAs($user)->get('/application/'.$application->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('value="08:30"', false);
        $response->assertSee('value="18:30"', false);
        $response->assertSee('value="12:30"', false);
        $response->assertSee('value="13:30"', false);
        $response->assertSee('打刻の時刻を誤ったため');
        $response->assertDontSee('value="09:00"', false);
        $response->assertDontSee('value="18:00"', false);
        $response->assertDontSee('value="12:00"', false);
        $response->assertDontSee('value="13:00"', false);
        $response->assertSee('value="山田太郎"', false);
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="6月1日"', false);
        $response->assertSee('承認待ちのため修正はできません。');
        $response->assertSee('action="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * 申請の休憩が0行なら、breaks が空の配列になることを確かめる。
     * 申請の休憩がないときに、テストから toDetailData() を呼び、申請の休憩を並べて、breaks が空の配列かを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_data_without_breaks(): void
    {
        $application = Application::factory()->create();

        $this->assertSame([], $application->toDetailData()['breaks']);
    }

    /**
     * 同じ人と日付の勤怠があるなら、id がその勤怠の id になることを確かめる。
     * 同じ人の 2026-05-31 と 2026-06-01 の勤怠と、入りの遅い順に作った申請の休憩2行があるときに、テストから toDetailData() を呼び、id が 2026-06-01 の勤怠の id、year が「2026年」、date が「6月1日」、breaks が break_in の昇順かを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_data_breaks(): void
    {
        $user = User::factory()->create();
        // 勤怠と申請の id が同じ値だと、申請の id を返す誤りでもテストが通る。そのため、先に別の日の勤怠を作って id をずらす。
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31']);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '15:00:00', 'break_out' => '15:15:00']);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $detailData = $application->toDetailData();

        $this->assertSame($attendanceRecord->id, $detailData['id']);
        $this->assertSame('2026年', $detailData['year']);
        $this->assertSame('6月1日', $detailData['date']);
        $this->assertSame(
            [['break_in' => '12:00', 'break_out' => '13:00'], ['break_in' => '15:00', 'break_out' => '15:15']],
            $detailData['breaks'],
        );
    }

    /**
     * 同じ人と日付の勤怠がないなら、id が null になることを確かめる。
     * 勤怠がないときに、テストから 2026-06-01 の申請の toDetailData() を呼び、勤怠を探して、id が null で full_date が 2026-06-01 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_data_id_is_null_without_record(): void
    {
        $application = Application::factory()->create(['date' => '2026-06-01']);

        $detailData = $application->toDetailData();

        $this->assertNull($detailData['id']);
        $this->assertSame('2026-06-01', $detailData['full_date']);
    }

    /**
     * 一般ユーザーが休憩のある修正を送った後なら、管理者の承認待ちの表にその申請が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で修正を送った後に、管理者が GET /stamp_correction_request/list を開き、submit() が作った申請の名前、日付、備考が 200 で承認待ちの表に出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_submitted_application_is_shown_to_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:30',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $pendingTable = Str::between($response->getContent(), 'id="content1"', 'id="content2"');
        $this->assertStringContainsString('山田太郎', $pendingTable);
        $this->assertStringContainsString('2026/06/01', $pendingTable);
        $this->assertStringContainsString('打刻の時刻を誤ったため', $pendingTable);
    }

    /**
     * 一般ユーザーが休憩のある修正を送った後なら、管理者の申請詳細に送った値が出ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で修正を送った後に、管理者が GET /stamp_correction_request/approve/{id} を開き、submit() が保存した値、名前、日付、「承認」のボタンが 200 で admin.admin-application-detail に出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_submitted_application_detail_is_shown_to_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:30',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻の時刻を誤ったため',
        ]);
        $application = Application::sole();

        $response = $this->actingAs($admin)->get('/stamp_correction_request/approve/'.$application->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-detail');
        $response->assertSee('value="08:30"', false);
        $response->assertSee('value="18:30"', false);
        $response->assertSee('value="12:00"', false);
        $response->assertSee('value="13:00"', false);
        $response->assertSee('打刻の時刻を誤ったため');
        $response->assertSee('value="山田太郎"', false);
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="6月1日"', false);
        $response->assertSee('type="submit">承認</button>', false);
    }

    /**
     * 保存した勤怠に休憩のある修正を送ると、送った値で申請と申請の休憩が1行ずつできることを確かめる。
     * 自分の勤怠（09:00〜18:00、休憩 12:00〜13:00）に一般ユーザーが POST /attendance/{id} で修正を送り、submit() が申請を作って、勤怠と休憩が変わらず、/attendance/{id} へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_is_created(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:30',
            'new_break_in' => ['12:30'],
            'new_break_out' => ['13:30'],
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseHas('applications', [
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
            'is_approved' => false,
        ]);
        $this->assertDatabaseCount('proposal_breaks', 1);
        $this->assertDatabaseHas('proposal_breaks', ['break_in' => '12:30:00', 'break_out' => '13:30:00']);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => null,
        ]);
        $this->assertDatabaseHas('breaks', [
            'attendance_record_id' => $attendanceRecord->id,
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ]);
    }

    /**
     * 保存していない勤怠の日に修正を送ると、ログインしている人と 2026-06-02 で申請が1件できることを確かめる。
     * 2026-06-03 に、一般ユーザーが勤怠のない 2026-06-02 に POST /attendance/date/2026-06-02 で修正を送り、submit() が申請を作って、/attendance/date/2026-06-02 へ戻り、申請の休憩が1行でき、勤怠ができないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_is_created_for_day_without_record(): void
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
        $this->assertDatabaseHas('applications', ['user_id' => $user->id, 'date' => '2026-06-02']);
        $this->assertDatabaseCount('proposal_breaks', 1);
        $this->assertDatabaseCount('attendance_records', 0);
    }

    /**
     * 休憩を送らない修正なら、申請が1行でき、申請の休憩が0行であることを確かめる。
     * 一般ユーザーが自分の 2026-06-01 の勤怠に POST /attendance/{id} で出勤、退勤、備考だけを送り、submit() が申請を作って、申請の休憩が0行で /attendance/{id} へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_is_created_without_breaks(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-02 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:30',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('proposal_breaks', 0);
    }

    /**
     * 管理者が承認した後なら、申請した人の承認済みの表にその申請が出ることを確かめる。
     * 管理者が承認待ちの申請に POST /stamp_correction_request/approve/{id} を送った後に、一般ユーザーが申請一覧を開き、approve() が申請を承認済みにして、200 で承認待ちの表に出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_sees_approved_after_approve(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '打刻の時刻を誤ったため']);
        $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertOk();
        $pendingTable = Str::between($response->getContent(), 'id="content1"', 'id="content2"');
        $approvedTable = Str::after($response->getContent(), 'id="content2"');
        $this->assertStringContainsString('打刻の時刻を誤ったため', $approvedTable);
        $this->assertStringNotContainsString('打刻の時刻を誤ったため', $pendingTable);
    }

    /**
     * 同じ日の勤怠がある申請を承認すると、勤怠が申請の値（08:30〜18:30、備考）になることを確かめる。
     * 09:00〜18:00 で備考なしの勤怠があるときに、管理者が承認待ちの申請に POST /stamp_correction_request/approve/{id} を送り、approve() が勤怠を書き換えて、/stamp_correction_request/approve/{id} へ戻り、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_is_approved(): void
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
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response = $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $response->assertRedirect('/stamp_correction_request/approve/'.$application->id);
        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'clock_in' => '08:30:00',
            'clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 同じ日の勤怠がない申請を承認すると、その人の 2026-06-02 の勤怠が申請の値で1件できることを確かめる。
     * 勤怠がないときに、管理者が 2026-06-02 の承認待ちの申請に POST /stamp_correction_request/approve/{id} を送り、approve() が勤怠を作って、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approve_creates_record_for_day_without_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
        ]);

        $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'clock_in' => '08:30:00',
            'clock_out' => '18:30:00',
        ]);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 申請の後に管理者がその日の勤怠を作っていても、勤怠が1件のままで申請の値に書き換わることを確かめる。
     * 申請の後に管理者がその日の勤怠（09:00〜18:00）を作ったときに、管理者が POST /stamp_correction_request/approve/{id} を送り、approve() が勤怠を書き換えて、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approve_updates_record_made_after_application(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $this->actingAs($user)->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '08:30',
            'new_clock_out' => '18:30',
            'comment' => '打刻漏れのため',
        ]);
        $this->actingAs($admin)->post('/admin/attendance/staff/'.$user->id.'/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'comment' => '打刻漏れのため',
        ]);
        $application = Application::sole();

        $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', [
            'user_id' => $user->id,
            'date' => '2026-06-02',
            'clock_in' => '08:30:00',
            'clock_out' => '18:30:00',
        ]);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 勤怠に休憩が1行あり、申請の休憩が0行なら、承認すると勤怠の休憩が0行になることを確かめる。
     * 休憩1行の勤怠があるときに、管理者が承認待ちの申請に POST /stamp_correction_request/approve/{id} を送り、approve() が休憩を置き換えて、/stamp_correction_request/approve/{id} へ戻り、break_minutes が0、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_removed(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $response->assertRedirect('/stamp_correction_request/approve/'.$application->id);
        $this->assertDatabaseCount('breaks', 0);
        $this->assertSame(0, $attendanceRecord->fresh()->break_minutes);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 勤怠に休憩が2行あり、申請の休憩が 12:30〜13:30 の1行なら、承認すると勤怠の休憩がその1行になることを確かめる。
     * 休憩2行の勤怠があるときに、管理者が承認待ちの申請に POST /stamp_correction_request/approve/{id} を送り、approve() が休憩を置き換えて、/stamp_correction_request/approve/{id} へ戻り、break_minutes が 60、work_minutes が 540、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_replaced_and_recalculated(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '15:00:00', 'break_out' => '15:15:00']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:30:00', 'break_out' => '13:30:00']);

        $response = $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id);

        $response->assertRedirect('/stamp_correction_request/approve/'.$application->id);
        $attendanceBreak = $attendanceRecord->breaks()->sole();
        $this->assertSame('12:30:00', $attendanceBreak->break_in);
        $this->assertSame('13:30:00', $attendanceBreak->break_out);
        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(60, $attendanceRecord->break_minutes);
        $this->assertSame(540, $attendanceRecord->work_minutes);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 承認のフォームから comment を送っても、勤怠の comment が申請の備考になることを確かめる。
     * 管理者が承認待ちの申請に POST /stamp_correction_request/approve/{id} で comment「別の備考」を送り、approve() が申請の値で勤怠を書き換えて、/stamp_correction_request/approve/{id} へ戻り、is_approved が true になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_sent_values_are_ignored(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '打刻の時刻を誤ったため']);

        $response = $this->actingAs($admin)->post('/stamp_correction_request/approve/'.$application->id, ['comment' => '別の備考']);

        $response->assertRedirect('/stamp_correction_request/approve/'.$application->id);
        $this->assertDatabaseHas('attendance_records', ['id' => $attendanceRecord->id, 'comment' => '打刻の時刻を誤ったため']);
        $this->assertTrue($application->fresh()->is_approved);
    }

    /**
     * 申請の is_approved が true なら、承認済みの表に「承認済み」とその申請が出ることを確かめる。
     * 自分の承認済みの申請があるときに、一般ユーザーが GET /stamp_correction_request/list を開き、approvalStatus() が「承認済み」を返して、200 で承認済みの表に備考「承認された申請」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_applications_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Application::factory()->create(['user_id' => $user->id, 'comment' => '承認された申請', 'is_approved' => true]);

        $response = $this->actingAs($user)->get('/stamp_correction_request/list');

        $response->assertOk();
        $approvedTable = Str::after($response->getContent(), 'id="content2"');
        $this->assertStringContainsString('承認済み', $approvedTable);
        $this->assertStringContainsString('承認された申請', $approvedTable);
    }

    /**
     * 申請の is_approved が true なら、管理者の申請詳細に「承認済み」が出て、「承認」のボタンが出ないことを確かめる。
     * 承認済みの申請があるときに、管理者が GET /stamp_correction_request/approve/{id} を開き、approvalStatus() が「承認済み」を返して、200 で「承認済み」が出て、「承認」のボタンが出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_application_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $application = Application::factory()->create(['is_approved' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/approve/'.$application->id);

        $response->assertOk();
        $response->assertSee('<p class="applied-form__item">承認済み</p>', false);
        $response->assertDontSee('type="submit">承認</button>', false);
    }

    /**
     * 申請の is_approved が false なら、approval_status が「承認待ち」になることを確かめる。
     * テストから is_approved が false の申請の approval_status を読み、approvalStatus() が is_approved を見て、「承認待ち」を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approval_status_is_pending(): void
    {
        $application = Application::factory()->create(['is_approved' => false]);

        $this->assertSame('承認待ち', $application->approval_status);
    }

    /**
     * 申請の is_approved が true なら、approval_status が「承認済み」になることを確かめる。
     * テストから is_approved が true の申請の approval_status を読み、approvalStatus() が is_approved を見て、「承認済み」を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approval_status_is_approved(): void
    {
        $application = Application::factory()->create(['is_approved' => true]);

        $this->assertSame('承認済み', $application->approval_status);
    }

    /**
     * 申請の date が 2026-06-01 なら、new_date が 2026-06-01 の Carbon になることを確かめる。
     * テストから date が 2026-06-01 の申請の new_date を読み、newDate() が date を Carbon にして、2026-06-01 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_new_date_is_carbon_of_date(): void
    {
        $application = Application::factory()->create(['date' => '2026-06-01']);

        $this->assertInstanceOf(Carbon::class, $application->new_date);
        $this->assertSame('2026-06-01', $application->new_date->toDateString());
    }

    /**
     * 申請の date が 2026-06-01 なら、管理者の申請詳細に「2026年」「6月1日」が出ることを確かめる。
     * 一般ユーザーの承認待ちの申請があるときに、管理者が GET /stamp_correction_request/approve/{id} を開き、newDate() が date を Carbon にして、200 で admin.admin-application-detail に名前、申請の値、「承認」のボタン、送り先が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_application_is_shown_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $application = Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:30:00', 'break_out' => '13:30:00']);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/approve/'.$application->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-application-detail');
        $response->assertSee('value="山田太郎"', false);
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="6月1日"', false);
        $response->assertSee('value="08:30"', false);
        $response->assertSee('value="18:30"', false);
        $response->assertSee('value="12:30"', false);
        $response->assertSee('value="13:30"', false);
        $response->assertSee('打刻の時刻を誤ったため');
        $response->assertSee('type="submit">承認</button>', false);
        $response->assertSee('action="'.url('/stamp_correction_request/approve/'.$application->id).'"', false);
    }

    /**
     * 申請の created_at が 2026-06-02 10:00 なら、application_date がその日時の Carbon になることを確かめる。
     * テストから created_at が 2026-06-02 10:00 の申請の application_date を読み、applicationDate() が created_at を返して、2026-06-02 10:00:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_date_is_created_at(): void
    {
        $application = Application::factory()->create(['created_at' => '2026-06-02 10:00:00']);

        $this->assertSame('2026-06-02 10:00:00', $application->fresh()->application_date->format('Y-m-d H:i:s'));
    }
}
