<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Database\Seeders\AttendanceRecordSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

class AttendanceRecordSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * UserSeeder の7人がいるときに AttendanceRecordSeeder を流すと、7人とも勤怠が1行以上あって土日の勤怠がないことを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、run() が1人ずつ今日より前の平日に勤怠を作って、7人とも勤怠が1行以上あって全部の勤怠の日付が土日でないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_creates_normal_records(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $attendanceRecords = AttendanceRecord::all();
        $this->assertSame(7, $attendanceRecords->pluck('user_id')->unique()->count());
        $this->assertTrue($attendanceRecords->every(fn (AttendanceRecord $attendanceRecord): bool => ! Carbon::parse($attendanceRecord->date)->isWeekend()));
    }

    /**
     * 2026-07-31（金）に流すと、user1 以外の6人の勤怠が 2026-02-02〜2026-07-30 の平日の129日に入ることを確かめる。
     * UserSeeder を流した後に、2026-07-31 に AttendanceRecordSeeder を流し、run() が5か月前の月の初めから昨日までの平日に勤怠を作って、6人の勤怠の日付が 2026-02-02〜2026-07-30 の平日の129日かを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_range_at_month_end(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-31 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $dates = $this->otherUsersDates();
        $this->assertSame(129, $dates->count());
        $this->assertSame('2026-02-02', $dates->first());
        $this->assertSame('2026-07-30', $dates->last());
        $this->assertTrue($dates->every(fn (string $date): bool => ! Carbon::parse($date)->isWeekend()));
    }

    /**
     * 2026-11-02（月）に流すと、user1 以外の6人の勤怠が 2026-06-01〜2026-10-30 の平日の110日に入ることを確かめる。
     * UserSeeder を流した後に、2026-11-02 に AttendanceRecordSeeder を流し、run() が5か月前の月の初めから前の月の終わりまでの平日に勤怠を作って、6人の勤怠の日付が 2026-06-01〜2026-10-30 の平日の110日かを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_range_at_month_start(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $dates = $this->otherUsersDates();
        $this->assertSame(110, $dates->count());
        $this->assertSame('2026-06-01', $dates->first());
        $this->assertSame('2026-10-30', $dates->last());
        $this->assertTrue($dates->every(fn (string $date): bool => ! Carbon::parse($date)->isWeekend()));
    }

    /**
     * 2027-01-15（金）に流すと、user1 以外の6人の勤怠が前の年の 2026-08-03〜2027-01-14 の平日の119日に入ることを確かめる。
     * UserSeeder を流した後に、2027-01-15 に AttendanceRecordSeeder を流し、run() が前の年の5か月前の月の初めから昨日までの平日に勤怠を作って、6人の勤怠の日付が 2026-08-03〜2027-01-14 の平日の119日かを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_range_across_years(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2027-01-15 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $dates = $this->otherUsersDates();
        $this->assertSame(119, $dates->count());
        $this->assertSame('2026-08-03', $dates->first());
        $this->assertSame('2027-01-14', $dates->last());
        $this->assertTrue($dates->every(fn (string $date): bool => ! Carbon::parse($date)->isWeekend()));
    }

    /**
     * user1 の勤怠は、seedIntentionalData() で作る勤怠だけになることを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、run() が user1 を通常の勤怠を作るユーザーから除いて、user1 の勤怠が92行（2026-10 までの75行と 2026-11 の17行）だけで、user1 以外の6人とも勤怠が1行以上あるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_gets_only_intentional_records(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        $user1Records = AttendanceRecord::where('user_id', $user1->id)->get();
        $this->assertCount(92, $user1Records);
        $this->assertSame(75, $user1Records->filter(fn (AttendanceRecord $attendanceRecord): bool => $attendanceRecord->date < '2026-11-01')->count());
        $this->assertSame(17, $user1Records->filter(fn (AttendanceRecord $attendanceRecord): bool => str_starts_with($attendanceRecord->date, '2026-11'))->count());
        $this->assertTrue(User::whereKeyNot($user1->id)->get()->every(fn (User $user): bool => $user->attendanceRecords()->exists()));
        $this->assertSame(6, User::whereKeyNot($user1->id)->count());
    }

    /**
     * 2026-11-20 に流すと、user1 以外の人の勤怠に遅刻、早退、長時間労働、退勤なし、休憩なし、休憩2回の勤怠と勤怠のない平日が混ざることを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、resolveTimes() が $kind を割り切る数ごとに違う時刻を返して、user1 以外の人に6種類の勤怠があって行の数が744行より少ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_creates_varied_records(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        $queryOtherRecords = fn () => AttendanceRecord::where('user_id', '!=', $user1->id);
        $this->assertTrue($queryOtherRecords()->where('is_late_arrival', true)->exists());
        $this->assertTrue($queryOtherRecords()->where('is_early_departure', true)->exists());
        $this->assertTrue($queryOtherRecords()->where('is_long_work', true)->exists());
        $this->assertTrue($queryOtherRecords()->whereNull('clock_out')->exists());
        $this->assertTrue($queryOtherRecords()->doesntHave('breaks')->exists());
        $this->assertTrue($queryOtherRecords()->has('breaks', '=', 2)->exists());
        // 2026-06-01〜2026-11-19 の土日を除く日は124日なので、6人が全部の日に勤怠を持てば744行になる。
        $this->assertLessThan(744, $queryOtherRecords()->count());
    }

    /**
     * 2026-11-20 に流すと、user1 以外の人の勤怠に遅刻、早退、長時間労働、打刻漏れのどれでもない通常の日の勤怠があることを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、resolveTimes() がどの数でも割り切れない日に通常の時刻を返して、user1 以外の人に3つのフラグが false で clock_out があり休憩が1行の勤怠があるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_creates_regular_records(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        $this->assertTrue(AttendanceRecord::where('user_id', '!=', $user1->id)
            ->where('is_late_arrival', false)
            ->where('is_early_departure', false)
            ->where('is_long_work', false)
            ->whereNotNull('clock_out')
            ->has('breaks', '=', 1)
            ->exists());
    }

    /**
     * 流す日が年の最初の月でなければ、月末の日でも、user1 の勤怠がマイ勤怠レポートの範囲の各月に作られることを確かめる。
     * UserSeeder を流した後に、2026-07-31 に AttendanceRecordSeeder を流し、seedIntentionalData() が当月の1日から1〜5か月を引いた前の5か月の各月に15日ずつと当月に17日の勤怠を作って、user1 の勤怠が 2026-02〜2026-06 の各月に15行、2026-07 に17行かを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_records_cover_six_months(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-07-31 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $this->assertSame([
            '2026-02' => 15,
            '2026-03' => 15,
            '2026-04' => 15,
            '2026-05' => 15,
            '2026-06' => 15,
            '2026-07' => 17,
        ], $this->user1CountsByMonth());
    }

    /**
     * 流す日が年の最初の月の日でも、user1 の勤怠が前の年の月にも作られることを確かめる。
     * UserSeeder を流した後に、2027-01-15 に AttendanceRecordSeeder を流し、seedIntentionalData() が年をまたいで前の年の月にも15日ずつ勤怠を作って、user1 の勤怠が 2026-08〜2026-12 の各月に15行、2027-01 に17行かを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_records_cross_year(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2027-01-15 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $this->assertSame([
            '2026-08' => 15,
            '2026-09' => 15,
            '2026-10' => 15,
            '2026-11' => 15,
            '2026-12' => 15,
            '2027-01' => 17,
        ], $this->user1CountsByMonth());
    }

    /**
     * user1 の勤怠の勤務時間、残業時間、当月のフラグがマイ勤怠レポートの要件の内訳どおりになることを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、seedIntentionalData() が要件の内訳で渡された user1 の勤怠を作って勤務時間とフラグを計算し、user1 の勤怠の work_minutes の合計が 44640、overtime_minutes の合計が 600、2026-11 の勤怠で is_late_arrival が true の行が2行、is_early_departure が true の行が1行、is_long_work が true の行が1行で、user1 の勤怠が92行、work_minutes の合計を92で割った値の小数を切り捨てると 485（8 時間 5 分）、user1 の全部の勤怠に休憩 12:00〜13:00 が1行あるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_values_match_report_expectations(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        $user1Records = AttendanceRecord::where('user_id', $user1->id)->with('breaks')->get();
        $currentMonthRecords = $user1Records->filter(fn (AttendanceRecord $attendanceRecord): bool => str_starts_with($attendanceRecord->date, '2026-11'));
        $totalWorkMinutes = (int) $user1Records->sum('work_minutes');
        $this->assertSame(44640, $totalWorkMinutes);
        $this->assertSame(600, (int) $user1Records->sum('overtime_minutes'));
        $this->assertSame(2, $currentMonthRecords->where('is_late_arrival', true)->count());
        $this->assertSame(1, $currentMonthRecords->where('is_early_departure', true)->count());
        $this->assertSame(1, $currentMonthRecords->where('is_long_work', true)->count());
        $this->assertCount(92, $user1Records);
        $this->assertSame(485, intdiv($totalWorkMinutes, $user1Records->count()));
        $this->assertTrue($user1Records->every(fn (AttendanceRecord $attendanceRecord): bool => $attendanceRecord->breaks->count() === 1
            && $attendanceRecord->breaks->first()->break_in === '12:00:00'
            && $attendanceRecord->breaks->first()->break_out === '13:00:00'));
    }

    /**
     * 今日が平日なら、user1 の当月の勤怠は今日を除いた日に作られることを確かめる。
     * UserSeeder を流した後に、2026-11-02 に AttendanceRecordSeeder を流し、pickWeekdays() が今日を選ぶ日から除いて、user1 の 2026-11 の勤怠が 2026-11-03〜2026-11-25 の土日を除く17日にあり、2026-11-02 の勤怠がないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_current_month_skips_today(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-02 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $dates = $this->user1DatesOfMonth('2026-11');
        $this->assertSame([
            '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06',
            '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13',
            '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20',
            '2026-11-23', '2026-11-24', '2026-11-25',
        ], $dates);
        $this->assertNotContains('2026-11-02', $dates);
    }

    /**
     * 今日が土日なら、user1 の当月の勤怠は月の最初の平日から作られることを確かめる。
     * UserSeeder を流した後に、2026-11-07 に AttendanceRecordSeeder を流し、pickWeekdays() が除く日のないまま月の最初の平日から選んで、user1 の 2026-11 の勤怠が 2026-11-02〜2026-11-24 の土日を除く17日にあるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_current_month_starts_on_first_weekday(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-07 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $this->assertSame([
            '2026-11-02', '2026-11-03', '2026-11-04', '2026-11-05', '2026-11-06',
            '2026-11-09', '2026-11-10', '2026-11-11', '2026-11-12', '2026-11-13',
            '2026-11-16', '2026-11-17', '2026-11-18', '2026-11-19', '2026-11-20',
            '2026-11-23', '2026-11-24',
        ], $this->user1DatesOfMonth('2026-11'));
    }

    /**
     * user1 の前の月の勤怠は、月の初めから土日を除いた日に作られることを確かめる。
     * UserSeeder を流した後に、2026-11-20 に AttendanceRecordSeeder を流し、pickWeekdays() が前の月の初めから土日を除く日を古い順に15日選んで、user1 の 2026-10 の勤怠が 2026-10-01〜2026-10-21 の土日を除く15日にあるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user1_past_month_days_are_first_weekdays(): void
    {
        $this->seed(UserSeeder::class);
        $this->travelTo(Carbon::parse('2026-11-20 10:00:00'));

        $this->seed(AttendanceRecordSeeder::class);

        $this->assertSame([
            '2026-10-01', '2026-10-02',
            '2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09',
            '2026-10-12', '2026-10-13', '2026-10-14', '2026-10-15', '2026-10-16',
            '2026-10-19', '2026-10-20', '2026-10-21',
        ], $this->user1DatesOfMonth('2026-10'));
    }

    /**
     * user1 以外の6人の勤怠の日付を、重なりを除いて古い順に返す。
     * 出勤の打刻漏れの日はユーザーの番号で変わるので、1人ではなく6人をまとめて見ると、範囲のどの平日もだれかの勤怠に入る。
     *
     * @return Collection<int, string> 日付（Y-m-d）
     */
    private function otherUsersDates(): Collection
    {
        $user1 = User::where('email', 'user1@example.com')->firstOrFail();

        return AttendanceRecord::where('user_id', '!=', $user1->id)->pluck('date')->unique()->sort()->values();
    }

    /**
     * user1 の勤怠の数を日付の年と月（Y-m）ごとに古い順に返す。
     *
     * @return array<string, int> 年と月ごとの勤怠の数
     */
    private function user1CountsByMonth(): array
    {
        $user1 = User::where('email', 'user1@example.com')->firstOrFail();

        return AttendanceRecord::where('user_id', $user1->id)
            ->pluck('date')
            ->countBy(fn (string $date): string => substr($date, 0, 7))
            ->sortKeys()
            ->all();
    }

    /**
     * user1 の勤怠のうち、渡した年と月の日付を古い順に返す。
     *
     * @param  string  $month  年と月（例：2026-11）
     * @return array<int, string> 日付（Y-m-d）
     */
    private function user1DatesOfMonth(string $month): array
    {
        $user1 = User::where('email', 'user1@example.com')->firstOrFail();

        return AttendanceRecord::where('user_id', $user1->id)
            ->where('date', 'like', $month.'-%')
            ->orderBy('date')
            ->pluck('date')
            ->all();
    }
}
