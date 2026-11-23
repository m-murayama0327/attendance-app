<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateBreaksTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 休憩がある勤怠を消すと、休憩が breaks からなくなることを確かめる。
     * 勤怠に休憩 12:00〜13:00 があるときに、テストから勤怠の delete() を呼び、up() が attendance_record_id の外部キーの cascadeOnDelete() で休憩も消して、休憩が breaks にないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_deleting_record_deletes_breaks(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $attendanceBreak = AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $attendanceRecord->delete();

        $this->assertDatabaseMissing('breaks', ['id' => $attendanceBreak->id]);
    }
}
