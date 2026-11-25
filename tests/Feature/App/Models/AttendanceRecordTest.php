<?php

namespace Tests\Feature\App\Models;

use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\ProposalBreak;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class AttendanceRecordTest extends TestCase
{
    use RefreshDatabase;

    // monthlyRows() が1日分の行に持たせるキー（提供された Blade と勤怠一覧の画面が読む名前）
    private const ROW_KEYS = ['date', 'full_date', 'clock_in', 'clock_out', 'total_break_time', 'total_time', 'id'];

    /**
     * fill() に is_late を true、work_minutes を 999 で渡しても、2つが null のままであることを確かめる。
     * テストから AttendanceRecord の fill() に is_late と work_minutes を渡し、$fillable が2つを受け付けず、is_late と work_minutes が null のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_calculated_values_are_not_fillable(): void
    {
        $attendanceRecord = new AttendanceRecord;

        $attendanceRecord->fill(['is_late' => true, 'work_minutes' => 999]);

        $this->assertNull($attendanceRecord->is_late);
        $this->assertNull($attendanceRecord->work_minutes);
    }

    /**
     * 3つのフラグが渡さずに作った既定の 0 なら、bool の false で読めることを確かめる。
     * テストから勤怠を作って fresh() で読み直し、$casts が is_late、is_early_leave、is_long_work を真偽値にして、3つとも false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_flags_are_cast_to_bool(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create()->fresh();

        $this->assertFalse($attendanceRecord->is_late);
        $this->assertFalse($attendanceRecord->is_early_leave);
        $this->assertFalse($attendanceRecord->is_long_work);
    }

    /**
     * 同じ人の 2026-06-01 と 2026-06-02 の申請があるなら、2026-06-01 の勤怠を消すと、同じ日の申請だけが消えることを確かめる。
     * 同じ人と日付の申請（申請の休憩1行）と他の日の申請があるときに、テストから勤怠の delete() を呼び、booted() が同じ人と日付の申請を消して、申請の休憩も消えるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_deleting_record_deletes_applications(): void
    {
        $user = User::factory()->create();
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        ProposalBreak::factory()->create(['application_id' => $application->id]);
        $otherDayApplication = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);

        $attendanceRecord->delete();

        $this->assertModelMissing($application);
        $this->assertModelExists($otherDayApplication);
        $this->assertDatabaseCount('proposal_breaks', 0);
    }

    /**
     * 勤怠の user_id が作ったユーザーの id なら、user() がそのユーザーを返すことを確かめる。
     * テストから勤怠を作って user を読み、user() が勤怠の user_id でユーザーを探して、作ったユーザーを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_belongs_to_user(): void
    {
        $user = User::factory()->create();
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id]);

        $this->assertTrue($attendanceRecord->user->is($user));
    }

    /**
     * この勤怠の休憩と他の勤怠の休憩が1行ずつあっても、この勤怠の休憩の行だけを返すことを確かめる。
     * 2つの勤怠に休憩が1行ずつあるときに、テストから1つ目の勤怠の breaks を読み、breaks() が attendance_record_id で絞って、1つ目の勤怠の行だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_has_breaks(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();
        $otherAttendanceRecord = AttendanceRecord::factory()->create();
        $attendanceBreak = AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $otherAttendanceRecord->id]);

        $this->assertSame([$attendanceBreak->id], $attendanceRecord->breaks->pluck('id')->all());
    }

    /**
     * 他の人の同じ日の申請があっても、同じ人と日付の申請だけを返すことを確かめる。
     * 同じ人と他の人の 2026-06-01 の申請があるときに、テストから勤怠の sameDayApplications() を呼び、同じ人と日付で申請を探して、同じ人の申請だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_finds_same_day_applications(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        Application::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $this->assertSame([$application->id], $attendanceRecord->sameDayApplications()->pluck('id')->all());
    }

    /**
     * 同じ人の他の日の申請があっても、同じ人と日付の申請だけを返すことを確かめる。
     * 同じ人の 2026-06-01 と 2026-06-02 の申請があるときに、テストから 2026-06-01 の勤怠の sameDayApplications() を呼び、同じ人と日付で申請を探して、同じ日の申請だけを返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_same_day_applications_ignore_other_days(): void
    {
        $user = User::factory()->create();
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02']);

        $this->assertSame([$application->id], $attendanceRecord->sameDayApplications()->pluck('id')->all());
    }

    /**
     * 休憩がないなら、break_minutes が 0 になることを確かめる。
     * 9:00〜18:00 で休憩のない勤怠があるときに、テストから recalculate() を呼び、休憩と勤務の分を計算して、work_minutes が 540、overtime_minutes が 60 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_without_breaks(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', []);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(0, $attendanceRecord->break_minutes);
        $this->assertSame(540, $attendanceRecord->work_minutes);
        $this->assertSame(60, $attendanceRecord->overtime_minutes);
    }

    /**
     * 勤務の分が 480 なら、overtime_minutes が 0 になることを確かめる。
     * 9:00〜18:00 で休憩 12:00〜13:00 の勤怠があるときに、テストから recalculate() を呼び、勤務の分を計算して、work_minutes が 480、break_minutes が 60、3つのフラグが false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_normal_day(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(0, $attendanceRecord->overtime_minutes);
        $this->assertSame(480, $attendanceRecord->work_minutes);
        $this->assertSame(60, $attendanceRecord->break_minutes);
        $this->assertFalse($attendanceRecord->is_late);
        $this->assertFalse($attendanceRecord->is_early_leave);
        $this->assertFalse($attendanceRecord->is_long_work);
    }

    /**
     * 休憩が 12:00〜13:00 と 15:00〜15:15 なら、break_minutes が 75 になることを確かめる。
     * 9:00〜18:00 で休憩2行の勤怠があるときに、テストから recalculate() を呼び、2行の休憩の分を足して、work_minutes が 465 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_with_multiple_breaks(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [
            ['break_in' => '12:00:00', 'break_out' => '13:00:00'],
            ['break_in' => '15:00:00', 'break_out' => '15:15:00'],
        ]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(75, $attendanceRecord->break_minutes);
        $this->assertSame(465, $attendanceRecord->work_minutes);
    }

    /**
     * 休憩が 12:00 からの戻りのない休憩だけなら、break_minutes が 0 になることを確かめる。
     * 9:00 出勤で退勤がなく、戻りのない休憩だけの勤怠があるときに、テストから recalculate() を呼び、戻りのない休憩を除いて、work_minutes が空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_ignores_open_break(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', null, [['break_in' => '12:00:00', 'break_out' => null]]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(0, $attendanceRecord->break_minutes);
        $this->assertNull($attendanceRecord->work_minutes);
    }

    /**
     * 退勤がないなら、work_minutes と overtime_minutes が空で、is_early_leave と is_long_work が false になることを確かめる。
     * work_minutes、overtime_minutes、is_early_leave、is_long_work を 601、121、true、true にしておいた、9:00 出勤で退勤がなく、休憩 12:00〜13:00 の勤怠があるときに、テストから recalculate() を呼び、退勤のない勤怠を計算して、break_minutes が 60、is_late が false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_without_clock_out(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', null, [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->forceFill(['work_minutes' => 601, 'overtime_minutes' => 121, 'is_early_leave' => true, 'is_long_work' => true])->save();

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertNull($attendanceRecord->work_minutes);
        $this->assertNull($attendanceRecord->overtime_minutes);
        $this->assertFalse($attendanceRecord->is_early_leave);
        $this->assertFalse($attendanceRecord->is_long_work);
        $this->assertSame(60, $attendanceRecord->break_minutes);
        $this->assertFalse($attendanceRecord->is_late);
    }

    /**
     * 出勤が 9:00:30、退勤が 18:00:10 なら、work_minutes が 480 になることを確かめる。
     * 休憩 12:00〜13:00 の勤怠があるときに、テストから recalculate() を呼び、toMinutes() が出勤と退勤の秒を切り捨てて、work_minutes が 480 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_truncates_clock_seconds(): void
    {
        $attendanceRecord = $this->createRecord('09:00:30', '18:00:10', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $this->assertSame(480, $attendanceRecord->fresh()->work_minutes);
    }

    /**
     * 休憩が 12:00:40〜13:00:10 なら、break_minutes が 60 になることを確かめる。
     * 9:00〜18:00 の勤怠があるときに、テストから recalculate() を呼び、toMinutes() が休憩の入りと戻りの秒を切り捨てて、work_minutes が 480 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_truncates_break_seconds(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:40', 'break_out' => '13:00:10']]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(60, $attendanceRecord->break_minutes);
        $this->assertSame(480, $attendanceRecord->work_minutes);
    }

    /**
     * 出勤が 9:00:59 なら、is_late が false になることを確かめる。
     * is_late を true にしておいた 9:00:59 出勤の勤怠があるときに、テストから recalculate() を呼び、出勤の時刻で遅刻を決めて、is_late が false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_not_late_at_nine_with_seconds(): void
    {
        $attendanceRecord = $this->createRecord('09:00:59', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->forceFill(['is_late' => true])->save();

        $attendanceRecord->recalculate();

        $this->assertFalse($attendanceRecord->fresh()->is_late);
    }

    /**
     * 出勤が 9:01:00 なら、is_late が true になることを確かめる。
     * 9:01:00 出勤の勤怠があるときに、テストから recalculate() を呼び、出勤の時刻で遅刻を決めて、is_late が true かを見る。
     *
     * @return void 返す値はない
     */
    public function test_late_at_nine_one(): void
    {
        $attendanceRecord = $this->createRecord('09:01:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $this->assertTrue($attendanceRecord->fresh()->is_late);
    }

    /**
     * 退勤が 18:00:00 なら、is_early_leave が false になることを確かめる。
     * is_early_leave を true にしておいた 18:00:00 退勤の勤怠があるときに、テストから recalculate() を呼び、退勤の時刻で早退を決めて、is_early_leave が false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_not_early_leave_at_eighteen(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->forceFill(['is_early_leave' => true])->save();

        $attendanceRecord->recalculate();

        $this->assertFalse($attendanceRecord->fresh()->is_early_leave);
    }

    /**
     * 退勤が 17:59:59 なら、is_early_leave が true になることを確かめる。
     * 17:59:59 退勤の勤怠があるときに、テストから recalculate() を呼び、退勤の時刻で早退を決めて、is_early_leave が true かを見る。
     *
     * @return void 返す値はない
     */
    public function test_early_leave_before_eighteen(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '17:59:59', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $this->assertTrue($attendanceRecord->fresh()->is_early_leave);
    }

    /**
     * 9:00〜18:01 で休憩 12:00〜13:00 なら、overtime_minutes が 1 になることを確かめる。
     * 9:00〜18:01 で休憩1時間の勤怠があるときに、テストから recalculate() を呼び、8時間を超えた分を残業にして、work_minutes が 481 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_overtime_above_eight_hours(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:01:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(1, $attendanceRecord->overtime_minutes);
        $this->assertSame(481, $attendanceRecord->work_minutes);
    }

    /**
     * 8:00〜19:00 で休憩 12:00〜13:00 なら、is_long_work が false になることを確かめる。
     * is_long_work を true にしておいた 8:00〜19:00 で休憩1時間の勤怠があるときに、テストから recalculate() を呼び、勤務の分で長時間労働を決めて、work_minutes が 600 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_not_long_work_at_ten_hours(): void
    {
        $attendanceRecord = $this->createRecord('08:00:00', '19:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->forceFill(['is_long_work' => true])->save();

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertFalse($attendanceRecord->is_long_work);
        $this->assertSame(600, $attendanceRecord->work_minutes);
    }

    /**
     * 8:00〜19:01 で休憩 12:00〜13:00 なら、is_long_work が true になることを確かめる。
     * 8:00〜19:01 で休憩1時間の勤怠があるときに、テストから recalculate() を呼び、勤務の分で長時間労働を決めて、work_minutes が 601、overtime_minutes が 121 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_long_work_above_ten_hours(): void
    {
        $attendanceRecord = $this->createRecord('08:00:00', '19:01:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertTrue($attendanceRecord->is_long_work);
        $this->assertSame(601, $attendanceRecord->work_minutes);
        $this->assertSame(121, $attendanceRecord->overtime_minutes);
    }

    /**
     * 9:30〜18:00 で計算して遅刻になった後に出勤を 9:00 に直すと、is_late が false になることを確かめる。
     * 9:30 出勤の勤怠で1回計算した後に出勤を 9:00 に直したときに、テストから recalculate() をもう一度呼び、計算し直して、work_minutes が 480 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_overwrites_old_values(): void
    {
        $attendanceRecord = $this->createRecord('09:30:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->recalculate();
        $this->assertTrue($attendanceRecord->fresh()->is_late);
        $attendanceRecord->clock_in = '09:00:00';

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertFalse($attendanceRecord->is_late);
        $this->assertSame(480, $attendanceRecord->work_minutes);
    }

    /**
     * 1回計算した後に同じインスタンスの勤怠に休憩 15:00〜15:15 を足すと、break_minutes が 75 になることを確かめる。
     * 休憩 12:00〜13:00 の勤怠で1回計算した後に休憩を足したときに、テストから同じインスタンスで recalculate() を呼び、足した休憩も読んで、work_minutes が 465 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_recalculate_reads_new_breaks(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->recalculate();
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '15:00:00', 'break_out' => '15:15:00']);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(75, $attendanceRecord->break_minutes);
        $this->assertSame(465, $attendanceRecord->work_minutes);
    }

    /**
     * 出勤が 9:30、退勤が 17:00 なら、is_late と is_early_leave が true になることを確かめる。
     * 9:30〜17:00 の勤怠があるときに、テストから recalculate() を呼び、出勤と退勤の時刻で遅刻と早退を決めて、is_late と is_early_leave が true かを見る。
     *
     * @return void 返す値はない
     */
    public function test_late_and_early_leave_together(): void
    {
        $attendanceRecord = $this->createRecord('09:30:00', '17:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertTrue($attendanceRecord->is_late);
        $this->assertTrue($attendanceRecord->is_early_leave);
    }

    /**
     * 休憩が 12:00〜13:00 と 15:00 からの戻りのない休憩なら、break_minutes が 60 になることを確かめる。
     * 9:00 出勤で退勤がなく、休憩2行の勤怠があるときに、テストから recalculate() を呼び、戻りのない休憩を除いて、work_minutes が空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_closed_and_open_breaks_together(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', null, [
            ['break_in' => '12:00:00', 'break_out' => '13:00:00'],
            ['break_in' => '15:00:00', 'break_out' => null],
        ]);

        $attendanceRecord->recalculate();

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame(60, $attendanceRecord->break_minutes);
        $this->assertNull($attendanceRecord->work_minutes);
    }

    /**
     * 今日の勤怠がないときに出勤を打つと、勤怠一覧の 2026-06-01 の行の clock_in が 09:00 になることを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが 2026-06-01 09:00:00 に action=clock_in を送った後に GET /attendance/list を開き、clockIn() が勤怠を作って、行の clock_in が 09:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_is_shown_after_stamp(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-01')['clock_in'] === '09:00');
    }

    /**
     * 今日の勤怠がないときに出勤と休憩を打つと、管理者の勤怠一覧にその人の名前の後に「09:00」と「1:00」が出ることを確かめる。
     * 一般ユーザーが 2026-06-01 に 09:00 に出勤、12:00 に休憩入、13:00 に休憩戻を打った後に、管理者が GET /admin/attendance/list を開き、clockIn() が作った勤怠が 200 で出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_stamps_are_shown_to_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);
        $this->stamp($user, '2026-06-01 13:00:00', AttendanceRecord::ACTION_BREAK_OUT);

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertOk();
        $response->assertSeeInOrder(['山田太郎', '09:00', '1:00']);
    }

    /**
     * 2026-06-01 09:00:00 に作った勤怠があるときに 09:05:00 に出勤を打っても、勤怠が1行で clock_in が 09:00:00 のままであることを確かめる。
     * 09:00:00 に clockIn() を呼んで作った勤怠があるときに、テストから 09:05:00 に同じ人で clockIn() を呼び、エラーにならず、勤怠が1行で clock_in が 09:00:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_twice_keeps_first_value(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 09:00:00'));
        AttendanceRecord::clockIn($user);
        $this->travelTo(Carbon::parse('2026-06-01 09:05:00'));

        AttendanceRecord::clockIn($user);

        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00']);
    }

    /**
     * 出勤の打刻の途中で0時を過ぎても、date と clock_in が同じ1つの今の日時から作られることを確かめる。
     * テストから、最初に読む今の日時だけが 2026-11-30 23:59:59 で2回目からは 2026-12-01 00:00:00 になる時計で clockIn() を呼び、clockIn() が今の日時を1回だけ取って、date が 2026-11-30、clock_in が 23:59:59 の勤怠ができるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_keeps_date_across_midnight(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $isFirstRead = true;
        $this->travelTo(function () use (&$isFirstRead): Carbon {
            $now = Carbon::parse($isFirstRead ? '2026-11-30 23:59:59' : '2026-12-01 00:00:00');
            $isFirstRead = false;

            return $now;
        });

        AttendanceRecord::clockIn($user);

        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-11-30', 'clock_in' => '23:59:59']);
    }

    /**
     * 打刻する日時が 2026-06-01 09:30:15 なら、date が 2026-06-01、clock_in が 09:30:15 の勤怠ができることを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが POST /attendance に action=clock_in を送り、clockIn() が今の日時で勤怠を作って、/attendance に移り、「出勤中」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_saves_time(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->stamp($user, '2026-06-01 09:30:15', AttendanceRecord::ACTION_CLOCK_IN);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:30:15']);
        $this->actingAs($user)->get('/attendance')->assertSee('出勤中');
    }

    /**
     * 今日の勤怠がないときに 09:30:15 に出勤を打つと、作った勤怠の is_late が true と計算されることを確かめる。
     * 今日の勤怠がないときに、一般ユーザーが 2026-06-01 09:30:15 に POST /attendance に action=clock_in を送り、clockIn() が作った勤怠を計算して、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_in_calculates_new_record(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->stamp($user, '2026-06-01 09:30:15', AttendanceRecord::ACTION_CLOCK_IN);

        $response->assertRedirect('/attendance');
        $this->assertTrue(AttendanceRecord::sole()->is_late);
    }

    /**
     * 退勤を押す時刻が 2026-06-01 18:00:00 なら、勤怠一覧の 2026-06-01 の行の clock_out が 18:00 になることを確かめる。
     * 09:00 に出勤したときに、一般ユーザーが 18:00:00 に action=clock_out を送った後に GET /attendance/list を開き、clockOut() が退勤を書いて、行の clock_out が 18:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_is_shown_after_stamp(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 18:00:00', AttendanceRecord::ACTION_CLOCK_OUT);

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-01')['clock_out'] === '18:00');
    }

    /**
     * 出勤が 09:00:00 で退勤を押す時刻が 09:00:01 なら、clock_out が 09:00:01 になることを確かめる。
     * 09:00:00 に出勤したときに、一般ユーザーが 2026-06-01 09:00:01 に POST /attendance に action=clock_out を送り、clockOut() が退勤を書いて、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_one_second_after_clock_in_is_saved(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);

        $response = $this->stamp($user, '2026-06-01 09:00:01', AttendanceRecord::ACTION_CLOCK_OUT);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'clock_out' => '09:00:01']);
    }

    /**
     * 出勤が 09:00:00 で退勤を押す時刻も 09:00:00 なら、clock_out が空のままであることを確かめる。
     * 09:00:00 に出勤したときに、一般ユーザーが 09:00:00 に POST /attendance に action=clock_out を送り、clockOut() が出勤と同じ時刻の退勤を書かずに、/attendance に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_at_same_second_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);

        $response = $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_OUT);

        $response->assertRedirect('/attendance');
        $this->assertNull(AttendanceRecord::sole()->clock_out);
    }

    /**
     * 退勤を押す時刻が 2026-06-01 18:00:20 なら、clock_out が 18:00:20 になることを確かめる。
     * 09:00 に出勤したときに、一般ユーザーが POST /attendance に action=clock_out を送り、clockOut() が退勤を書いて計算し、/attendance に移り、work_minutes が 540 で、「退勤済」と「お疲れ様でした。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_saves_time(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);

        $response = $this->stamp($user, '2026-06-01 18:00:20', AttendanceRecord::ACTION_CLOCK_OUT);

        $response->assertRedirect('/attendance');
        $attendanceRecord = AttendanceRecord::sole();
        $this->assertSame('18:00:20', $attendanceRecord->clock_out);
        $this->assertSame(540, $attendanceRecord->work_minutes);
        $this->actingAs($user)->get('/attendance')->assertSee('退勤済')->assertSee('お疲れ様でした。');
    }

    /**
     * 今日の勤怠に閉じた休憩があるときに休憩入を打つと、休憩戻のボタンが出ることを確かめる。
     * 09:00 に出勤、12:00 に休憩入、12:30 に休憩戻を打ったときに、一般ユーザーが 15:00 に action=break_in を送った後に GET /attendance を開き、breakIn() が休憩を作って、「休憩中」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_can_repeat(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);
        $this->stamp($user, '2026-06-01 12:30:00', AttendanceRecord::ACTION_BREAK_OUT);
        $this->stamp($user, '2026-06-01 15:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $response = $this->actingAs($user)->get('/attendance');

        $response->assertSee('value="break_out"', false);
        $response->assertSee('休憩中');
    }

    /**
     * 休憩入を押す時刻が 12:00:00 なら、break_in が 12:00:00、break_out が空の休憩が1行できることを確かめる。
     * 09:00 に出勤して退勤がないときに、一般ユーザーが POST /attendance に action=break_in を送り、breakIn() が休憩を作って、/attendance に移り、「休憩中」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_creates_row(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);

        $response = $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('breaks', 1);
        $this->assertDatabaseHas('breaks', ['break_in' => '12:00:00', 'break_out' => null]);
        $this->actingAs($user)->get('/attendance')->assertSee('休憩中');
    }

    /**
     * 休憩戻を押す時刻が 13:00 なら、勤怠一覧の今日の行の total_break_time が 01:00 になることを確かめる。
     * 12:00 に休憩入を打ったときに、一般ユーザーが 13:00 に action=break_out を送った後に GET /attendance/list を開き、breakOut() が休憩を閉じて計算し、今日の行が 01:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_is_shown_after_stamp(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);
        $this->stamp($user, '2026-06-01 13:00:00', AttendanceRecord::ACTION_BREAK_OUT);

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-01')['total_break_time'] === '01:00');
    }

    /**
     * 休憩の入りが 12:00:00 で休憩戻を押す時刻が 12:00:01 なら、break_out が 12:00:01 になることを確かめる。
     * 12:00:00 からの戻りのない休憩だけがあるときに、一般ユーザーが 12:00:01 に POST /attendance に action=break_out を送り、breakOut() が休憩を閉じて、break_out が 12:00:01 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_one_second_after_break_in_is_saved(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $this->stamp($user, '2026-06-01 12:00:01', AttendanceRecord::ACTION_BREAK_OUT);

        $this->assertDatabaseHas('breaks', ['break_in' => '12:00:00', 'break_out' => '12:00:01']);
    }

    /**
     * 休憩の入りが 12:00:00 で休憩戻を押す時刻も 12:00:00 なら、休憩の break_out が空のままであることを確かめる。
     * 12:00:00 からの戻りのない休憩だけがあるときに、一般ユーザーが 12:00:00 に POST /attendance に action=break_out を送り、breakOut() が入りと同じ時刻の戻りを書かずに、「休憩中」のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_at_same_second_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_OUT);

        $this->assertDatabaseHas('breaks', ['break_in' => '12:00:00', 'break_out' => null]);
        $this->actingAs($user)->get('/attendance')->assertSee('休憩中');
    }

    /**
     * 閉じた休憩と戻りのない休憩があるときに休憩戻を打つと、休憩が2行で、1行目が 12:00〜12:30 のままであることを確かめる。
     * 休憩 12:00〜12:30 と戻りのない休憩があるときに、一般ユーザーが 15:15 に POST /attendance に action=break_out を送り、breakOut() が戻りのない休憩を閉じて、break_minutes が 45 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_can_repeat(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);
        $this->stamp($user, '2026-06-01 12:30:00', AttendanceRecord::ACTION_BREAK_OUT);
        $this->stamp($user, '2026-06-01 15:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $this->stamp($user, '2026-06-01 15:15:00', AttendanceRecord::ACTION_BREAK_OUT);

        $this->assertDatabaseCount('breaks', 2);
        $this->assertDatabaseHas('breaks', ['break_in' => '12:00:00', 'break_out' => '12:30:00']);
        $this->assertSame(45, AttendanceRecord::sole()->break_minutes);
    }

    /**
     * 休憩戻を押す時刻が 13:00:00 なら、同じ行の break_out が 13:00:00 になることを確かめる。
     * 12:00 に休憩入を打ったときに、一般ユーザーが POST /attendance に action=break_out を送り、breakOut() が休憩を閉じて、/attendance に移り、休憩が1行のまま、break_minutes が 60 で、「出勤中」とボタン break_in が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_out_closes_row(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 12:00:00', AttendanceRecord::ACTION_BREAK_IN);

        $response = $this->stamp($user, '2026-06-01 13:00:00', AttendanceRecord::ACTION_BREAK_OUT);

        $response->assertRedirect('/attendance');
        $this->assertDatabaseCount('breaks', 1);
        $this->assertDatabaseHas('breaks', ['break_in' => '12:00:00', 'break_out' => '13:00:00']);
        $this->assertSame(60, AttendanceRecord::sole()->break_minutes);
        $this->actingAs($user)->get('/attendance')->assertSee('出勤中')->assertSee('value="break_in"', false);
    }

    /**
     * 既存の休憩の行も追加用の行も両方空なら、申請が1行でき、申請の休憩が0行であることを確かめる。
     * 休憩1行の自分の勤怠があるときに、一般ユーザーが POST /attendance/{id} で両方空の休憩の行を送り、toBreakRows() が空の行を除いて、/attendance/{id} へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_blank_breaks_are_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id]);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['', ''],
            'new_break_out' => ['', ''],
            'comment' => '休憩を取らなかったため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('proposal_breaks', 0);
    }

    /**
     * 休憩の1行目が 12:00〜13:00 で2行目が両方空なら、申請の休憩が1行だけできることを確かめる。
     * 2026-06-03 に、一般ユーザーが POST /attendance/date/2026-06-02 で休憩の2行を送り、toBreakRows() が空の行を除いて、申請が1件でき、/attendance/date/2026-06-02 に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_blank_break_row_is_ignored(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->post('/attendance/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00', ''],
            'new_break_out' => ['13:00', ''],
            'comment' => '打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/date/2026-06-02');
        $this->assertDatabaseCount('applications', 1);
        $this->assertDatabaseCount('proposal_breaks', 1);
    }

    /**
     * その日に勤怠がない一般ユーザーの 2026-06-02 に送ると、その人の勤怠が1件でき、休憩が1行、work_minutes が 480 になることを確かめる。
     * 2026-06-03 に、管理者が POST /admin/attendance/staff/{id}/date/2026-06-02 で出勤、退勤、休憩1行を送り、createFromAdminEdit() が勤怠を作って計算し、/attendance/{id} に移るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_creates_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->post('/admin/attendance/staff/'.$user->id.'/date/2026-06-02', [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '打刻漏れのため',
        ]);

        $attendanceRecord = AttendanceRecord::sole();
        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertSame($user->id, $attendanceRecord->user_id);
        $this->assertSame('2026-06-02', $attendanceRecord->date);
        $this->assertDatabaseCount('breaks', 1);
        $this->assertSame(480, $attendanceRecord->work_minutes);
    }

    /**
     * 送った時刻が出勤 09:00、退勤 18:00 なら、clock_in が 09:00:30 のままであることを確かめる。
     * 出勤 09:00:30、退勤 18:00:30 の勤怠があるときに、テストから rewrite() を呼び、keepSeconds() が時と分の同じ時刻の秒を残して、clock_out が 18:00:30 のままかを見る。
     *
     * @return void 返す値はない
     */
    public function test_unchanged_seconds_are_kept(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create(['clock_in' => '09:00:30', 'clock_out' => '18:00:30']);

        $attendanceRecord->rewrite('09:00', '18:00', '在宅勤務のため', []);

        $attendanceRecord = $attendanceRecord->fresh();
        $this->assertSame('09:00:30', $attendanceRecord->clock_in);
        $this->assertSame('18:00:30', $attendanceRecord->clock_out);
    }

    /**
     * 送った時刻が出勤 09:30 なら、clock_in が 09:30:00 になることを確かめる。
     * 出勤 09:00:30 の勤怠があるときに、テストから出勤 09:30、退勤 18:00 で rewrite() を呼び、keepSeconds() が時と分の違う時刻を送った値にして、clock_in が 09:30:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_changed_time_is_written(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create(['clock_in' => '09:00:30']);

        $attendanceRecord->rewrite('09:30', '18:00', '打刻の時刻を誤ったため', []);

        $this->assertSame('09:30:00', $attendanceRecord->fresh()->clock_in);
    }

    /**
     * $comment が「在宅勤務のため」なら、勤怠の comment が「在宅勤務のため」になることを確かめる。
     * 備考のない勤怠があるときに、テストから出勤 09:00、退勤 18:00、休憩なしで rewrite() を呼び、rewrite() が備考を書き換えて、comment が「在宅勤務のため」かを見る。
     *
     * @return void 返す値はない
     */
    public function test_comment_is_updated(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create(['comment' => null]);

        $attendanceRecord->rewrite('09:00', '18:00', '在宅勤務のため', []);

        $this->assertSame('在宅勤務のため', $attendanceRecord->fresh()->comment);
    }

    /**
     * $clockOut が 09:00 なら、勤怠の clock_out が 09:00:00 になることを確かめる。
     * 出勤 09:00:30、退勤 18:00:40 の勤怠があるときに、テストから出勤 08:00、退勤 09:00 で rewrite() を呼び、rewrite() が退勤を今の退勤とだけ比べて、clock_out が 09:00:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_clock_out_is_not_matched_with_old_clock_in(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create(['clock_in' => '09:00:30', 'clock_out' => '18:00:40']);

        $attendanceRecord->rewrite('08:00', '09:00', '打刻の時刻を誤ったため', []);

        $this->assertSame('09:00:00', $attendanceRecord->fresh()->clock_out);
    }

    /**
     * 送った休憩が 12:00〜13:00 なら、休憩が 12:00:20〜13:00:40 のまま置き換わることを確かめる。
     * 休憩 12:00:20〜13:00:40 の勤怠があるときに、テストから replaceBreaks() を呼び、keepSeconds() が時と分の同じ時刻の秒を残して、休憩が 12:00:20〜13:00:40 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_unchanged_break_seconds_are_kept(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:20', 'break_out' => '13:00:40']);

        $attendanceRecord->replaceBreaks([['break_in' => '12:00', 'break_out' => '13:00']]);

        $attendanceBreak = $attendanceRecord->breaks()->sole();
        $this->assertSame('12:00:20', $attendanceBreak->break_in);
        $this->assertSame('13:00:40', $attendanceBreak->break_out);
    }

    /**
     * 送った休憩が 12:30〜13:30 と 15:00〜15:15 なら、休憩がその2行に置き換わることを確かめる。
     * 休憩 12:00〜13:00 の勤怠があるときに、テストから replaceBreaks() を呼び、replaceBreaks() が前の休憩を消して送った休憩を作り、break_minutes が 75 に計算し直されるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_replaced_with_new_values(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $attendanceRecord->replaceBreaks([
            ['break_in' => '12:30', 'break_out' => '13:30'],
            ['break_in' => '15:00', 'break_out' => '15:15'],
        ]);

        $this->assertSame(
            [['12:30:00', '13:30:00'], ['15:00:00', '15:15:00']],
            $attendanceRecord->breaks()->orderBy('break_in')->get()->map(fn (AttendanceBreak $attendanceBreak): array => [$attendanceBreak->break_in, $attendanceBreak->break_out])->all(),
        );
        $this->assertSame(75, $attendanceRecord->fresh()->break_minutes);
    }

    /**
     * 送った休憩が 13:00〜14:00 なら、休憩の break_in が 13:00:00 になることを確かめる。
     * 休憩 12:00:20〜13:00:40 の勤怠があるときに、テストから replaceBreaks() を呼び、replaceBreaks() が消す前の入りとだけ比べて、break_out が 14:00:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_break_in_is_not_matched_with_old_break_out(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:20', 'break_out' => '13:00:40']);

        $attendanceRecord->replaceBreaks([['break_in' => '13:00', 'break_out' => '14:00']]);

        $attendanceBreak = $attendanceRecord->breaks()->sole();
        $this->assertSame('13:00:00', $attendanceBreak->break_in);
        $this->assertSame('14:00:00', $attendanceBreak->break_out);
    }

    /**
     * その人の 2026-06-01 の勤怠（9:00〜18:00、休憩 12:00〜13:00）があるなら、勤怠一覧のその日の行に7つの値が入ることを確かめる。
     * 2026-06-01 の勤怠があるときに、一般ユーザーが 2026-06-15 に GET /attendance/list を開き、monthlyRows() が行を作って、画面に「1:00」と /attendance/{id} のリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_row_values_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        $attendanceRecord->recalculate();
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-01') === [
            'date' => '06/01(月)',
            'full_date' => '2026-06-01',
            'clock_in' => '09:00',
            'clock_out' => '18:00',
            'total_break_time' => '01:00',
            'total_time' => '08:00',
            'id' => $attendanceRecord->id,
        ]);
        $response->assertSee('1:00');
        $response->assertSee('href="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * その人の勤怠（09:00 出勤）と他の人の勤怠（07:45 出勤）があるなら、09:00 が出て、07:45 が出ないことを確かめる。
     * 自分と他の人の勤怠があるときに、一般ユーザーが 2026-06-15 に GET /attendance/list を開き、monthlyRows() がその人の勤怠だけを選んで、200 で user.user-attendance-list を出すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_only_own_records_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00']);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-02', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertOk();
        $response->assertViewIs('user.user-attendance-list');
        $response->assertSee('09:00');
        $response->assertDontSee('07:45');
    }

    /**
     * 選んだ人の勤怠（09:00 出勤）と他の一般ユーザーの勤怠（07:45 出勤）があるなら、09:00 が出て、07:45 が出ないことを確かめる。
     * 2人の勤怠があるときに、管理者が 2026-06-15 に GET /admin/attendance/staff/{id} を開き、monthlyRows() が選んだ人の勤怠だけを選んで、200 で admin.staff-attendance-list に「山田太郎さんの勤怠」と CSV 出力の user_id が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_selected_users_records_are_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $otherUser = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00']);
        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id);

        $response->assertOk();
        $response->assertViewIs('admin.staff-attendance-list');
        $response->assertSee('09:00');
        $response->assertDontSee('07:45');
        $response->assertSee('山田太郎さんの勤怠');
        $response->assertSee('name="user_id" value="'.$user->id.'"', false);
    }

    /**
     * 一般ユーザーの 2026-06-01 の勤怠があるなら、スタッフ別の勤怠一覧に /attendance/{id} のリンクが出ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、管理者が 2026-06-15 に GET /admin/attendance/staff/{id} を開き、monthlyRows() が勤怠の id を行に入れて、200 でそのリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_link_on_day_with_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id);

        $response->assertOk();
        $response->assertSee('href="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * その月（2026-07）の勤怠がないなら、7月の31日分の行があり、どの行も clock_in と clock_out が空であることを確かめる。
     * 一般ユーザーが 2026-06-15 に GET /attendance/list?date=2026-07 を開き、monthlyRows() がその月の全部の日の行を作って、200 で31日分の空の行を渡すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_future_month_shows_empty_rows(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list?'.http_build_query(['date' => '2026-07']));

        $response->assertOk();
        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => count($rows) === 31
            && collect($rows)->every(fn (array $row): bool => $row['clock_in'] === null && $row['clock_out'] === null));
    }

    /**
     * $month が 2026-02（28日）なら、行が28で、どの行にもキーが7つあることを確かめる。
     * テストから 2026-02 で monthlyRows() を呼び、monthlyRows() がその月の全部の日の行を作って、28行のどれもが full_date を含む7つのキーを持つかを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_rows_cover_february(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $rows = AttendanceRecord::monthlyRows($user, Carbon::parse('2026-02-01'));

        $this->assertCount(28, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => array_keys($row) === self::ROW_KEYS));
    }

    /**
     * $month が 2026-06（30日）なら、行が30で、どの行にもキーが7つあることを確かめる。
     * テストから 2026-06 で monthlyRows() を呼び、monthlyRows() がその月の全部の日の行を作って、30行のどれもが full_date を含む7つのキーを持つかを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_rows_cover_30_day_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $rows = AttendanceRecord::monthlyRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => array_keys($row) === self::ROW_KEYS));
    }

    /**
     * $month が 2026-07（31日）なら、行が31で、どの行にもキーが7つあることを確かめる。
     * テストから 2026-07 で monthlyRows() を呼び、monthlyRows() がその月の全部の日の行を作って、31行のどれもが full_date を含む7つのキーを持つかを見る。
     *
     * @return void 返す値はない
     */
    public function test_monthly_rows_cover_31_day_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $rows = AttendanceRecord::monthlyRows($user, Carbon::parse('2026-07-01'));

        $this->assertCount(31, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => array_keys($row) === self::ROW_KEYS));
    }

    /**
     * 退勤がない日は、CSV の行の合計が空になることを確かめる。
     * 一般ユーザー（name が「山田太郎」）の退勤がない勤怠があるときに、テストから 2026-06 の日を渡して csvRows() を呼び、csvRows() が total_time の null を合計に null のまま入れて、2026-06-01 の行の7つ目（合計）が null で、4つ目（出勤）が 09:00、5つ目（退勤）と6つ目（休憩）が null かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_without_clock_out(): void
    {
        $user = User::factory()->create(['name' => '山田太郎', 'admin_status' => false]);
        $this->createUserRecord($user, '09:00:00', null, []);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertNull($rows[0][6]);
        $this->assertSame('09:00', $rows[0][3]);
        $this->assertNull($rows[0][4]);
        $this->assertNull($rows[0][5]);
    }

    /**
     * 休憩と退勤がある日は、CSV の行の休憩と合計が先頭の0のない時間の形になることを確かめる。
     * 一般ユーザー（name が「山田太郎」）の休憩と退勤がある勤怠があるときに、テストから 2026-06 の日を渡して csvRows() を呼び、csvRows() が total_break_time と total_time の 01:00 の形を先頭の0のない形にして、2026-06-01 の行の6つ目（休憩）が 1:00、7つ目（合計）が 8:00 で、3つ目が 06/01(月)、4つ目が 09:00、5つ目が 18:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_format_break_and_total(): void
    {
        $user = User::factory()->create(['name' => '山田太郎', 'admin_status' => false]);
        $this->createUserRecord($user, '09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertSame('1:00', $rows[0][5]);
        $this->assertSame('8:00', $rows[0][6]);
        $this->assertSame('06/01(月)', $rows[0][2]);
        $this->assertSame('09:00', $rows[0][3]);
        $this->assertSame('18:00', $rows[0][4]);
    }

    /**
     * 休憩がない日は、CSV の行の休憩が空になることを確かめる。
     * 一般ユーザー（name が「山田太郎」）の休憩がない勤怠があるときに、テストから 2026-06 の日を渡して csvRows() を呼び、csvRows() が total_break_time の null を休憩に null のまま入れて、2026-06-01 の行の6つ目（休憩）が null で、7つ目（合計）が 9:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_without_break(): void
    {
        $user = User::factory()->create(['name' => '山田太郎', 'admin_status' => false]);
        $this->createUserRecord($user, '09:00:00', '18:00:00', []);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertNull($rows[0][5]);
        $this->assertSame('9:00', $rows[0][6]);
    }

    /**
     * 勤怠のない日も含めて、CSV の全部の行の先頭に渡したユーザーの id と name が入ることを確かめる。
     * テストから 2026-06 の勤怠がない一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、csvRows() が勤怠のない日も含めて全部の行の先頭に渡したユーザーの id と name を入れて、30行の全部で1つ目が渡したユーザーの id、2つ目が「山田太郎」で、3つ目が 06/01(月) などの日付、4つ目から7つ目が null かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_start_with_user_id_and_name(): void
    {
        $user = User::factory()->create(['name' => '山田太郎', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id && $row[1] === '山田太郎'));
        $this->assertSame('06/01(月)', $rows[0][2]);
        $this->assertSame('06/30(火)', $rows[29][2]);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => array_slice($row, 3) === [null, null, null, null]));
    }

    /**
     * 開く日が 2026-06-01 で ?date= がないなら、管理者の勤怠一覧に「2026/06/01」が出ることを確かめる。
     * 管理者が 2026-06-01 に GET /admin/attendance/list を開き、parseDateQuery() が今日を返して、$previousDay が 2026-05-31、$nextDay が 2026-06-02 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_today_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list');

        $response->assertSee('2026/06/01');
        $response->assertViewHas('previousDay', '2026-05-31');
        $response->assertViewHas('nextDay', '2026-06-02');
    }

    /**
     * 開く日が 2026-06-15 で ?date= がないなら、勤怠一覧に「2026/06」が出ることを確かめる。
     * 一般ユーザーが 2026-06-15 に GET /attendance/list を開き、parseDateQuery() が今日を返して、$previousMonth が 2026-05、$nextMonth が 2026-07 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_current_month_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertSee('2026/06');
        $response->assertViewHas('previousMonth', '2026-05');
        $response->assertViewHas('nextMonth', '2026-07');
    }

    /**
     * ?date= が「前日」の送り先の 2026-05-31 なら、「2026/05/31」と 08:15 が出ることを確かめる。
     * 一般ユーザーの 2026-05-31 の勤怠（08:15〜17:15）があるときに、管理者が 2026-06-01 の勤怠一覧から「前日」の送り先を開き、parseDateQuery() が日付を読んで、その日の勤怠が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_previous_day_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-31', 'clock_in' => '08:15:00', 'clock_out' => '17:15:00']);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));
        $previousDay = $this->actingAs($admin)->get('/admin/attendance/list')->viewData('previousDay');

        $response = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => $previousDay]));

        $response->assertSee('2026/05/31');
        $response->assertSee('08:15');
    }

    /**
     * ?date= が「翌日」の送り先の 2026-06-02 なら、「2026/06/02」と 08:15 が出ることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠（08:15〜17:15）があるときに、管理者が 2026-06-03 に 2026-06-01 の勤怠一覧から「翌日」の送り先を開き、parseDateQuery() が日付を読んで、その日の勤怠が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_day_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-02', 'clock_in' => '08:15:00', 'clock_out' => '17:15:00']);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));
        $nextDay = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => '2026-06-01']))->viewData('nextDay');

        $response = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => $nextDay]));

        $response->assertSee('2026/06/02');
        $response->assertSee('08:15');
    }

    /**
     * ?date= が「2026-06-01 12:30:00」なら、「2026/06/01」と 08:15 が出ることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠（08:15〜17:15）があるときに、管理者が 2026-06-03 に GET /admin/attendance/list を開き、parseDateQuery() が日付だけを読んで、その日の勤怠が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_time_uses_date_only(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '08:15:00', 'clock_out' => '17:15:00']);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => '2026-06-01 12:30:00']));

        $response->assertSee('2026/06/01');
        $response->assertSee('08:15');
    }

    /**
     * ?date= が「前月」の送り先の 2026-05 なら、「2026/05」、05/10、08:15 が出て、07:45 が出ないことを確かめる。
     * 自分の 2026-05-10（08:15 出勤）と 2026-06-10（07:45 出勤）の勤怠があるときに、一般ユーザーが 2026-06-15 に勤怠一覧から「前月」の送り先を開き、parseDateQuery() が月を読むかを見る。
     *
     * @return void 返す値はない
     */
    public function test_previous_month_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-10', 'clock_in' => '08:15:00']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-10', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $previousMonth = $this->actingAs($user)->get('/attendance/list')->viewData('previousMonth');

        $response = $this->actingAs($user)->get('/attendance/list?'.http_build_query(['date' => $previousMonth]));

        $response->assertSee('2026/05');
        $response->assertSee('05/10');
        $response->assertSee('08:15');
        $response->assertDontSee('07:45');
    }

    /**
     * ?date= が「翌月」の送り先の 2026-07 なら、「2026/07」、07/10、08:15 が出て、07:45 が出ないことを確かめる。
     * 自分の 2026-07-10（08:15 出勤）と 2026-06-10（07:45 出勤）の勤怠があるときに、一般ユーザーが 2026-07-15 に 2026-06 の勤怠一覧から「翌月」の送り先を開き、parseDateQuery() が月を読むかを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_month_is_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-07-10', 'clock_in' => '08:15:00']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-10', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-07-15 10:00:00'));
        $nextMonth = $this->actingAs($user)->get('/attendance/list?'.http_build_query(['date' => '2026-06']))->viewData('nextMonth');

        $response = $this->actingAs($user)->get('/attendance/list?'.http_build_query(['date' => $nextMonth]));

        $response->assertSee('2026/07');
        $response->assertSee('07/10');
        $response->assertSee('08:15');
        $response->assertDontSee('07:45');
    }

    /**
     * ?date= がスタッフ別の勤怠一覧の「前月」の送り先の 2026-05 なら、「2026/05」、05/10、08:15 が出て、07:45 が出ないことを確かめる。
     * 一般ユーザーの 2026-05-10 と 2026-06-10 の勤怠があるときに、管理者がスタッフ別の勤怠一覧から「前月」の送り先を開き、parseDateQuery() が月を読んで、CSV 出力の year_month が 2026-05 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_previous_month_is_shown_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-05-10', 'clock_in' => '08:15:00']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-10', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $previousMonth = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id)->viewData('previousMonth');

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'?'.http_build_query(['date' => $previousMonth]));

        $response->assertSee('2026/05');
        $response->assertSee('05/10');
        $response->assertSee('08:15');
        $response->assertDontSee('07:45');
        $response->assertSee('name="year_month" value="2026-05"', false);
    }

    /**
     * ?date= がスタッフ別の勤怠一覧の「翌月」の送り先の 2026-07 なら、「2026/07」、07/10、08:15 が出て、07:45 が出ないことを確かめる。
     * 一般ユーザーの 2026-07-10 と 2026-06-10 の勤怠があるときに、管理者が 2026-07-15 に 2026-06 のスタッフ別の勤怠一覧から「翌月」の送り先を開き、parseDateQuery() が月を読むかを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_month_is_shown_for_admin(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-07-10', 'clock_in' => '08:15:00']);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-10', 'clock_in' => '07:45:00']);
        $this->travelTo(Carbon::parse('2026-07-15 10:00:00'));
        $nextMonth = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'?'.http_build_query(['date' => '2026-06']))->viewData('nextMonth');

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'?'.http_build_query(['date' => $nextMonth]));

        $response->assertSee('2026/07');
        $response->assertSee('07/10');
        $response->assertSee('08:15');
        $response->assertDontSee('07:45');
    }

    /**
     * 開く日が 2026-06-01 で ?date= が abc なら、今日の「2026/06/01」が出ることを確かめる。
     * 管理者が 2026-06-01 に GET /admin/attendance/list?date=abc を開き、parseDateQuery() が読めない日付を今日にして、200 で「2026/06/01」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_invalid_date_shows_today(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => 'abc']));

        $response->assertOk();
        $response->assertSee('2026/06/01');
    }

    /**
     * 開く日が 2026-06-01 で ?date= が配列なら、今日の「2026/06/01」が出ることを確かめる。
     * 管理者が 2026-06-01 に GET /admin/attendance/list?date[]=x を開き、parseDateQuery() が文字でない値を今日にして、200 で「2026/06/01」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_array_date_shows_today(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $this->travelTo(Carbon::parse('2026-06-01 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?'.http_build_query(['date' => ['x']]));

        $response->assertOk();
        $response->assertSee('2026/06/01');
    }

    /**
     * URL の日付が 2026-02-30 なら、404 になることを確かめる。
     * 一般ユーザーが GET /attendance/date/2026-02-30 を開き、parseDateParameter() が暦にない日付を読み替えずに null を返して、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_impossible_date_returns_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance/date/2026-02-30');

        $response->assertNotFound();
    }

    /**
     * URL の日付が abc なら、404 になることを確かめる。
     * 一般ユーザーが GET /attendance/date/abc を開き、parseDateParameter() が日付として読めない文字で null を返して、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_unreadable_date_returns_not_found(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $response = $this->actingAs($user)->get('/attendance/date/abc');

        $response->assertNotFound();
    }

    /**
     * 開く日が 2026-01-31 なら、$nextMonth が 2026-02、$previousMonth が 2025-12 になることを確かめる。
     * 一般ユーザーが 2026-01-31 に GET /attendance/list を開き、resolveMonthView() が月の初めから前月と翌月を数えて、$nextMonth と $previousMonth を渡すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_month_does_not_overflow(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-01-31 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('nextMonth', '2026-02');
        $response->assertViewHas('previousMonth', '2025-12');
    }

    /**
     * 開く日が 2026-12-15 なら、$nextMonth が 2027-01 になることを確かめる。
     * 一般ユーザーが 2026-12-15 に GET /attendance/list を開き、resolveMonthView() が翌月を数えて、$nextMonth が 2027-01 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_next_month_crosses_year(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-12-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('nextMonth', '2027-01');
    }

    /**
     * ?date= が 2026-06-15 なら、「2026/06」が出て、行が6月1日から始まることを確かめる。
     * 一般ユーザーが 2026-06-15 に GET /attendance/list?date=2026-06-15 を開き、resolveMonthView() が月の初めにして、1行目の full_date が 2026-06-01 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_date_is_moved_to_first_day_of_month(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list?'.http_build_query(['date' => '2026-06-15']));

        $response->assertSee('2026/06');
        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => $rows[0]['full_date'] === '2026-06-01');
    }

    /**
     * 同じ人と日付の承認待ちの申請（08:30〜18:30）があるなら、勤怠詳細に勤怠の 09:00 が出て、申請の 08:30 と修正のボタンが出ないことを確かめる。
     * 09:00〜18:00 の勤怠と承認待ちの申請があるときに、一般ユーザーが自分の勤怠を GET /attendance/{id} で開き、pendingApplication() が申請を選んで、200 で「承認待ちのため修正はできません。」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_record_is_read_only(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        Application::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '打刻の時刻を誤ったため',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertSee('value="09:00"', false);
        $response->assertDontSee('value="08:30"', false);
        $response->assertDontSee('type="submit">修正</button>', false);
        $response->assertSee('承認待ちのため修正はできません。');
    }

    /**
     * 同じ人と日付の申請が承認済みだけなら、修正を送ると申請が1行増えて2行になることを確かめる。
     * 承認済みの申請だけがあるときに、一般ユーザーが自分の勤怠に POST /attendance/{id} で修正を送り、pendingApplication() が承認済みの申請を選ばずに、申請が2行で /attendance/{id} へ戻るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_record_can_be_applied_again(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'is_approved' => true]);

        $response = $this->actingAs($user)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => ['13:00'],
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response->assertRedirect('/attendance/'.$attendanceRecord->id);
        $this->assertDatabaseCount('applications', 2);
    }

    /**
     * 同じ人と日付の申請がないなら、pendingApplication() が null を返すことを確かめる。
     * 申請がないときに、テストから勤怠の pendingApplication() を呼び、同じ人と日付の承認待ちの申請を探して、null を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_pending_application_is_null_without_applications(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();

        $this->assertNull($attendanceRecord->pendingApplication());
    }

    /**
     * 勤怠の休憩を 15:00〜15:15、12:00〜13:00 の順に作っても、12:00〜13:00、15:00〜15:15 の順で出ることを確かめる。
     * 休憩2行の勤怠があるときに、一般ユーザーが自分の勤怠を GET /attendance/{id} で開き、toDetailData() が休憩を入りの早い順に並べて、追加用の空の行が new_break_in[2] に出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_breaks_are_sorted_with_empty_row(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '15:00:00', 'break_out' => '15:15:00']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSeeInOrder(['value="12:00"', 'value="13:00"', 'value="15:00"', 'value="15:15"'], false);
        $response->assertSee('name="new_break_in[2]" value=""', false);
    }

    /**
     * 出勤 10:00 と空の備考を送って戻ったなら、送った 10:00 と空の備考が欄に残ることを確かめる。
     * 出勤 09:00、備考「在宅勤務のため」の勤怠に、一般ユーザーが POST /attendance/{id} で送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が前の入力を渡すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_old_input_is_kept(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'comment' => '在宅勤務のため']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '10:00',
            'new_clock_out' => '18:00',
            'comment' => '',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSee('id="new_clock_in" type="text" name="new_clock_in" value="10:00"', false);
        $response->assertSee('id="comment" name="comment"></textarea>', false);
    }

    /**
     * 退勤 19:00 と空の備考を送って戻ったなら、送った 19:00 が退勤の欄に残ることを確かめる。
     * 09:00〜18:00 の勤怠に、一般ユーザーが POST /attendance/{id} で送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が前の入力の退勤を渡して、退勤の欄が 19:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_old_clock_out_is_kept(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-02 10:00:00'));
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '19:00',
            'comment' => '',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSee('name="new_clock_out" value="19:00"', false);
    }

    /**
     * 休憩の1行目が 12:00〜13:00 で2行目が両方空なら、空の行が new_break_in[1] の1つだけ出て、new_break_in[2] が出ないことを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の2行と空の備考を送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が最後の空の行を除くかを見る。
     *
     * @return void 返す値はない
     */
    public function test_last_blank_row_is_not_doubled(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00', ''],
            'new_break_out' => ['13:00', ''],
            'comment' => '',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSee('name="new_break_in[1]" value=""', false);
        $response->assertDontSee('name="new_break_in[2]"', false);
    }

    /**
     * 休憩の1行目が 12:00〜13:00 で2行目が 15:00〜15:15 なら、2行目に送った 15:00〜15:15 が残ることを確かめる。
     * 一般ユーザーが自分の勤怠に POST /attendance/{id} で休憩の2行と空の備考を送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が2行目の休憩を new_break_in[1] に渡すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_last_filled_row_is_kept(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00', '15:00'],
            'new_break_out' => ['13:00', '15:15'],
            'comment' => '',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSee('name="new_break_in[1]" value="15:00"', false);
        $response->assertSee('name="new_break_out[1]" value="15:15"', false);
    }

    /**
     * 勤怠の休憩が0行なら、breaks が空の配列になることを確かめる。
     * 休憩のない勤怠があるときに、テストから送った値なしで toDetailData() を呼び、勤怠の休憩を並べて、breaks が空の配列かを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_data_without_breaks(): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create();

        $this->assertSame([], $attendanceRecord->toDetailData()['breaks']);
    }

    /**
     * new_break_in を文字 abc で送って戻ったなら、200 で休憩の欄が空の行で出ることを確かめる。
     * 一般ユーザーが POST /attendance/{id} に new_break_in を abc にして送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が配列でない値を除いて、new_break_in[0] が空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_string_break_in_is_ignored_on_back(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => 'abc',
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertSee('name="new_break_in[0]" value=""', false);
    }

    /**
     * 休憩の入り 12:00 と new_break_out の文字 abc を送って戻ったなら、200 で入りの欄に 12:00 が残り、戻りの欄が空で出ることを確かめる。
     * 一般ユーザーが POST /attendance/{id} に new_break_out を abc にして送ってエラーで戻った後に GET /attendance/{id} を開き、toDetailData() が配列でない戻りを除いて、new_break_out[0] が空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_string_break_out_is_ignored_on_back(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->actingAs($user)->from('/attendance/'.$attendanceRecord->id)->post('/attendance/'.$attendanceRecord->id, [
            'new_clock_in' => '09:00',
            'new_clock_out' => '18:00',
            'new_break_in' => ['12:00'],
            'new_break_out' => 'abc',
            'comment' => '休憩の打刻漏れのため',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertSee('name="new_break_in[0]" value="12:00"', false);
        $response->assertSee('name="new_break_out[0]" value=""', false);
    }

    /**
     * $minutes が 480 なら、total_time が 08:00 になることを確かめる。
     * 9:00〜18:00 で休憩 12:00〜13:00 の勤怠を recalculate() した後に、テストから total_time を読み、toHourMinuteText() が分を時と分にして、08:00 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_total_time(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->recalculate();

        $this->assertSame('08:00', $attendanceRecord->fresh()->total_time);
    }

    /**
     * $minutes が 465 なら、total_time が 07:45 になることを確かめる。
     * 9:00〜18:00 で休憩 12:00〜13:00 と 15:00〜15:15 の勤怠を recalculate() した後に、テストから total_time を読み、toHourMinuteText() が分を時と分にして、07:45 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_total_time_with_minutes(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [
            ['break_in' => '12:00:00', 'break_out' => '13:00:00'],
            ['break_in' => '15:00:00', 'break_out' => '15:15:00'],
        ]);
        $attendanceRecord->recalculate();

        $this->assertSame('07:45', $attendanceRecord->fresh()->total_time);
    }

    /**
     * work_minutes が 0 なら、total_time が 00:00 になることを確かめる。
     * 出勤 09:00:00、退勤 09:00:30、休憩なしの勤怠を recalculate() した後に、テストから total_time を読み、totalTime() が work_minutes を時と分にして、00:00 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_total_time_of_zero(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '09:00:30', []);
        $attendanceRecord->recalculate();

        $this->assertSame('00:00', $attendanceRecord->fresh()->total_time);
    }

    /**
     * work_minutes が空なら、total_time が null になることを確かめる。
     * 出勤 09:00:00、退勤なし、休憩なしの勤怠を recalculate() した後に、テストから total_time を読み、totalTime() が空の work_minutes で null を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_total_time_is_null_when_empty(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', null, []);
        $attendanceRecord->recalculate();

        $this->assertNull($attendanceRecord->fresh()->total_time);
    }

    /**
     * break_minutes が 0 なら、勤怠一覧の 2026-06-01 の行の total_break_time が null で、画面に「0:00」が出ないことを確かめる。
     * 休憩をせずに 2026-06-01 の 09:00 に出勤、18:00 に退勤を打ったときに、一般ユーザーが GET /attendance/list を開き、totalBreakTime() が 0 分を null にするかを見る。
     *
     * @return void 返す値はない
     */
    public function test_no_break_shows_empty_column(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->stamp($user, '2026-06-01 09:00:00', AttendanceRecord::ACTION_CLOCK_IN);
        $this->stamp($user, '2026-06-01 18:00:00', AttendanceRecord::ACTION_CLOCK_OUT);

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-01')['total_break_time'] === null);
        $response->assertDontSee('>0:00<', false);
    }

    /**
     * break_minutes が 60 なら、total_break_time が 01:00 になることを確かめる。
     * 休憩 12:00〜13:00 の勤怠を recalculate() した後に、テストから total_break_time を読み、totalBreakTime() が break_minutes を時と分にして、01:00 を返すかを見る。
     *
     * @return void 返す値はない
     */
    public function test_total_break_time(): void
    {
        $attendanceRecord = $this->createRecord('09:00:00', '18:00:00', [['break_in' => '12:00:00', 'break_out' => '13:00:00']]);
        $attendanceRecord->recalculate();

        $this->assertSame('01:00', $attendanceRecord->fresh()->total_break_time);
    }

    /**
     * 名前が等号で始まるなら、CSV の名前の先頭に数式を止める文字が付くことを確かめる。
     * テストから名前が =1+1 の一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、escapeFormula() が名前の1文字目を見てタブを付けて、30行の全部で2つ目がタブを付けた =1+1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_escape_name_starting_with_equals(): void
    {
        $user = User::factory()->create(['name' => '=1+1', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[1] === "\t=1+1"));
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id));
    }

    /**
     * 名前がプラス記号で始まるなら、CSV の名前の先頭に数式を止める文字が付くことを確かめる。
     * テストから名前が +1+1 の一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、escapeFormula() が名前の1文字目を見てタブを付けて、30行の全部で2つ目がタブを付けた +1+1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_escape_name_starting_with_plus(): void
    {
        $user = User::factory()->create(['name' => '+1+1', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[1] === "\t+1+1"));
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id));
    }

    /**
     * 名前がマイナス記号で始まるなら、CSV の名前の先頭に数式を止める文字が付くことを確かめる。
     * テストから名前が -1+1 の一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、escapeFormula() が名前の1文字目を見てタブを付けて、30行の全部で2つ目がタブを付けた -1+1 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_escape_name_starting_with_minus(): void
    {
        $user = User::factory()->create(['name' => '-1+1', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[1] === "\t-1+1"));
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id));
    }

    /**
     * 名前がアットマークで始まるなら、CSV の名前の先頭に数式を止める文字が付くことを確かめる。
     * テストから名前が @SUM(1+1) の一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、escapeFormula() が名前の1文字目を見てタブを付けて、30行の全部で2つ目がタブを付けた @SUM(1+1) かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_escape_name_starting_with_at_sign(): void
    {
        $user = User::factory()->create(['name' => '@SUM(1+1)', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[1] === "\t@SUM(1+1)"));
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id));
    }

    /**
     * 名前の2文字目より後に等号があるなら、CSV の名前がそのまま書かれることを確かめる。
     * テストから名前が 山田=太郎 の一般ユーザーと 2026-06 の日を渡して csvRows() を呼び、escapeFormula() が名前の1文字目だけを見て、30行の全部で2つ目が 山田=太郎 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_csv_rows_keep_name_with_equals_inside(): void
    {
        $user = User::factory()->create(['name' => '山田=太郎', 'admin_status' => false]);

        $rows = AttendanceRecord::csvRows($user, Carbon::parse('2026-06-01'));

        $this->assertCount(30, $rows);
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[1] === '山田=太郎'));
        $this->assertTrue(collect($rows)->every(fn (array $row): bool => $row[0] === $user->id));
    }

    /**
     * recalculate() のテストに使う 2026-06-01 の勤怠を、渡した出勤、退勤、休憩で作る。recalculate() はまだ呼ばない。
     *
     * @param  string  $clockIn  出勤（例：09:00:00）
     * @param  string|null  $clockOut  退勤（退勤がないときは null）
     * @param  array<int, array{break_in: string, break_out: string|null}>  $breaks  作る休憩の行（戻りがないときは break_out を null にする）
     * @return AttendanceRecord 作った勤怠
     */
    private function createRecord(string $clockIn, ?string $clockOut, array $breaks): AttendanceRecord
    {
        $attendanceRecord = AttendanceRecord::factory()->create([
            'date' => '2026-06-01',
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);
        $attendanceRecord->breaks()->createMany($breaks);

        return $attendanceRecord;
    }

    /**
     * csvRows() のテストに使う勤怠として、渡したユーザーの 2026-06-01 の勤怠を渡した出勤、退勤、休憩で作り、recalculate() で計算する。
     *
     * @param  User  $user  勤怠の持ち主の一般ユーザー
     * @param  string  $clockIn  出勤（例：09:00:00）
     * @param  string|null  $clockOut  退勤（退勤がないときは null）
     * @param  array<int, array{break_in: string, break_out: string}>  $breaks  作る休憩の行
     * @return void 返す値はない
     */
    private function createUserRecord(User $user, string $clockIn, ?string $clockOut, array $breaks): void
    {
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
        ]);
        $attendanceRecord->breaks()->createMany($breaks);
        $attendanceRecord->recalculate();
    }

    /**
     * 決めた日時に、その人で POST /attendance に打刻の種類を送る。
     *
     * @param  User  $user  打刻する一般ユーザー
     * @param  string  $time  打刻する日時（例：2026-06-01 09:00:00）
     * @param  string  $action  打刻の種類（AttendanceRecord の ACTION_ の定数）
     * @return TestResponse 打刻を送った応答
     */
    private function stamp(User $user, string $time, string $action): TestResponse
    {
        $this->travelTo(Carbon::parse($time));

        return $this->actingAs($user)->post('/attendance', ['action' => $action]);
    }
}
