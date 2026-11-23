<?php

namespace Tests\Feature\App\Http\Resources;

use App\Http\Resources\AttendanceRecordResource;
use App\Models\Application;
use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceRecordResourceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 関連を読んであるときは、勤怠と関連のキーが決めた順で返ることを確かめる。
     * ログインしていない人が勤怠（2026-06-01、出勤 09:00:00、退勤 18:00:00、休憩 12:00〜13:00 で recalculate() した勤怠、備考「在宅勤務のため」）を GET /api/v1/attendance-records/{id} で開き、toArray() が返すキーとその並びを選んで、200 で data のキーが id、user_id、user、date、clock_in、clock_out、total_time、total_break_time、comment、breaks、applications の順で、date が 2026-06-01、clock_in が 09:00:00、clock_out が 18:00:00、total_time が 08:00、total_break_time が 01:00、comment が「在宅勤務のため」かを見る。
     *
     * @return void 返す値はない
     */
    public function test_record_keys_are_returned_in_order(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '在宅勤務のため',
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);
        $attendanceRecord->recalculate();
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->getJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertOk();
        $this->assertSame(
            ['id', 'user_id', 'user', 'date', 'clock_in', 'clock_out', 'total_time', 'total_break_time', 'comment', 'breaks', 'applications'],
            array_keys($response->json('data')),
        );
        $response->assertJsonPath('data.date', '2026-06-01');
        $response->assertJsonPath('data.clock_in', '09:00:00');
        $response->assertJsonPath('data.clock_out', '18:00:00');
        $response->assertJsonPath('data.total_time', '08:00');
        $response->assertJsonPath('data.total_break_time', '01:00');
        $response->assertJsonPath('data.comment', '在宅勤務のため');
    }

    /**
     * 関連を読んでいないときは、返す配列に関連のキーが入らないことを確かめる。
     * テストから勤怠（2026-06-01、出勤 09:00:00、退勤 18:00:00）を関連を読まずに読み直して resolve() を呼び、toArray() の whenLoaded() が値のないことを示す値を返して、返す配列のキーが id、user_id、date、clock_in、clock_out、total_time、total_break_time、comment の8つだけで、user、breaks、applications がないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_relation_keys_are_omitted_when_not_loaded(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);

        $resourceArray = (new AttendanceRecordResource($attendanceRecord->fresh()))->resolve();

        $this->assertSame(['id', 'user_id', 'date', 'clock_in', 'clock_out', 'total_time', 'total_break_time', 'comment'], array_keys($resourceArray));
    }

    /**
     * 休憩も申請もない勤怠は、休憩と申請が空の配列で返ることを確かめる。
     * ログインしていない人が休憩も同じ日の申請もない勤怠（2026-06-01、出勤 09:00:00、退勤 18:00:00、計算し直した（休憩の分が0））を GET /api/v1/attendance-records/{id} で開き、toArray() の whenLoaded() が空のコレクションを返して、200 で data の breaks と applications が空の配列で、data に user の id と name があり、total_break_time が null かを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_relations_are_returned_as_empty_arrays(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ]);
        $attendanceRecord->recalculate();

        $response = $this->getJson('/api/v1/attendance-records/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertJsonPath('data.breaks', []);
        $response->assertJsonPath('data.applications', []);
        $response->assertJsonPath('data.user', ['id' => $user->id, 'name' => $user->name]);
        $response->assertJsonPath('data.total_break_time', null);
    }
}
