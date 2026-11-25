<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateAttendanceRecordsTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * is_late_arrival、is_early_departure、is_long_work を渡さずに勤怠を作ると、3つとも false になることを確かめる。
     * テストから AttendanceRecordFactory で3つのフラグを渡さずに 2026-06-01 の勤怠を作って fresh() で読み直し、up() が既定の値の false を書いて、3つのフラグが false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_flags_default_to_false(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $attendanceRecord = $attendanceRecord->fresh();

        $this->assertFalse($attendanceRecord->is_late_arrival);
        $this->assertFalse($attendanceRecord->is_early_departure);
        $this->assertFalse($attendanceRecord->is_long_work);
    }

    /**
     * 勤怠があるユーザーを消すと、QueryException（Cannot delete or update a parent row）になることを確かめる。
     * 2026-06-01 の勤怠があるときに、テストからユーザーの delete() を呼び、up() が user_id の外部キーの restrictOnDelete() で消す処理を止めて、QueryException になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_with_record_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertThrows(
            fn () => $user->delete(),
            QueryException::class,
            'Cannot delete or update a parent row'
        );
    }

    /**
     * ある人の 2026-06-01 の勤怠があるときに別の人の 2026-06-01 の勤怠を作ると、2人の勤怠が attendance_records にあることを確かめる。
     * ある人の 2026-06-01 の勤怠があるときに、テストから別の人の 2026-06-01 の勤怠を作り、up() のユニーク制約が user_id と date の組で重なりを調べて、2人の勤怠が attendance_records にあるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_other_user_can_have_same_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $otherUser = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        AttendanceRecord::factory()->create(['user_id' => $otherUser->id, 'date' => '2026-06-01']);

        $this->assertDatabaseHas('attendance_records', ['user_id' => $user->id, 'date' => '2026-06-01']);
        $this->assertDatabaseHas('attendance_records', ['user_id' => $otherUser->id, 'date' => '2026-06-01']);
    }

    /**
     * 同じ人の 2026-06-01 の勤怠を2行作ると、QueryException（Duplicate entry）になることを確かめる。
     * ある人の 2026-06-01 の勤怠があるときに、テストから同じ人の 2026-06-01 の勤怠を作り、up() のユニーク制約が2行目を止めて、QueryException になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_is_unique_per_user_and_date(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertThrows(
            fn () => AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']),
            QueryException::class,
            'Duplicate entry'
        );
    }
}
