<?php

namespace Tests\Feature\Config;

use App\Models\AttendanceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AppTest extends TestCase
{
    use RefreshDatabase;

    /**
     * UTC の 2026-06-01 00:30 に時刻を固定して勤怠を作ると、created_at が 2026-06-01 09:30:00 になることを確かめる。
     * テストから時刻を UTC の 2026-06-01 00:30 に固定して勤怠のファクトリーで勤怠を1行作り、timezone が Asia/Tokyo で今の日時を日本の時刻にして、created_at が 2026-06-01 09:30:00 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_timestamps_use_tokyo_time(): void
    {
        $this->travelTo(Carbon::parse('2026-06-01 00:30:00', 'UTC'));

        $attendanceRecord = AttendanceRecord::factory()->create([
            'date' => '2026-06-01',
            'clock_in' => '09:30:00',
            'clock_out' => null,
        ]);

        $this->assertDatabaseHas('attendance_records', [
            'id' => $attendanceRecord->id,
            'created_at' => '2026-06-01 09:30:00',
        ]);
    }
}
