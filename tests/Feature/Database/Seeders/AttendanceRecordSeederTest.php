<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\AttendanceRecordSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $otherRecords = fn () => AttendanceRecord::where('user_id', '!=', $user1->id);
        $this->assertTrue($otherRecords()->where('is_late', true)->exists());
        $this->assertTrue($otherRecords()->where('is_early_leave', true)->exists());
        $this->assertTrue($otherRecords()->where('is_long_work', true)->exists());
        $this->assertTrue($otherRecords()->whereNull('clock_out')->exists());
        $this->assertTrue($otherRecords()->doesntHave('breaks')->exists());
        $this->assertTrue($otherRecords()->has('breaks', '=', 2)->exists());
        // 2026-06-01〜2026-11-19 の土日を除く日は124日なので、6人が全部の日に勤怠を持てば744行になる。
        $this->assertLessThan(744, $otherRecords()->count());
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
            ->where('is_late', false)
            ->where('is_early_leave', false)
            ->where('is_long_work', false)
            ->whereNotNull('clock_out')
            ->has('breaks', '=', 1)
            ->exists());
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
}
