<?php

namespace Tests\Feature\Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AttendanceRecordFactoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 同じ user_id だけを渡して勤怠を100行作ると、100行の date が全部違うことを確かめる。
     * テストから同じ一般ユーザーの user_id だけを勤怠のファクトリーの make() に渡して100行作り、definition() が日数に unique() を付けて日付を重ならない値にして、100行の date が全部違うかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_factory_creates_rows_for_same_user(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $attendanceRecords = AttendanceRecord::factory()->count(100)->make(['user_id' => $user->id]);

        $this->assertSame(100, $attendanceRecords->pluck('date')->unique()->count());
    }

    /**
     * 2026-06-15 に user_id だけを渡して勤怠を作ると、date が 2025-06-15〜2026-06-14 の間になり、両端の日も入ることを確かめる。
     * テストから 2026-06-15 に時刻を固定して一般ユーザーの user_id だけを勤怠のファクトリーの make() に渡して365行作り、definition() が今日から1〜365日前の日を重ならずに入れて、date が全部 2025-06-15〜2026-06-14 の間で、一番前が 2025-06-15、一番後が 2026-06-14 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_factory_dates_are_before_today(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $user = User::factory()->create(['admin_status' => false]);

        $attendanceRecords = AttendanceRecord::factory()->count(365)->make(['user_id' => $user->id]);

        $this->assertTrue($attendanceRecords->every(fn (AttendanceRecord $attendanceRecord): bool => $attendanceRecord->date >= '2025-06-15' && $attendanceRecord->date <= '2026-06-14'));
        $this->assertSame('2025-06-15', $attendanceRecords->min('date'));
        $this->assertSame('2026-06-14', $attendanceRecords->max('date'));
    }
}
