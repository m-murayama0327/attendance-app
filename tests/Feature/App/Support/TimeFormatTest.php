<?php

namespace Tests\Feature\App\Support;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimeFormatTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 今日の勤怠の退勤が null なら、勤怠一覧の今日の行の clock_out が null になることを確かめる。
     * 09:00 出勤で退勤なしの今日の勤怠があるときに、一般ユーザーが GET /attendance/list を開き、toHourMinute() が null をそのまま返して、今日の行の clock_out と total_time が null かを見る。
     *
     * @return void 返す値はない
     */
    public function test_without_clock_out_shows_empty_columns(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-15',
            'clock_in' => '09:00:00',
            'clock_out' => null,
        ]);

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-15')['clock_out'] === null);
        $response->assertViewHas('formattedAttendanceRecords', fn (array $rows): bool => collect($rows)->firstWhere('full_date', '2026-06-15')['total_time'] === null);
    }

    /**
     * 出勤 09:00:23 と休憩の入り 12:00:10 なら、入力欄に 09:00 と 12:00 を出すことを確かめる。
     * 12:00:10 からの戻りのない休憩がある今日の勤怠を、一般ユーザーが GET /attendance/{id} で開き、toHourMinute() が秒を落として、入力欄が 09:00 と 12:00 になり、退勤と休憩の戻りが空かを見る。
     *
     * @return void 返す値はない
     */
    public function test_empty_and_seconds_are_formatted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-01 12:30:00'));
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:23',
            'clock_out' => null,
        ]);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:10', 'break_out' => null]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertSee('name="new_clock_in" value="09:00"', false);
        $response->assertSee('name="new_clock_out" value=""', false);
        $response->assertSee('name="new_break_in[0]" value="12:00"', false);
        $response->assertSee('name="new_break_out[0]" value=""', false);
    }

    /**
     * 日付が 2026-06-01 なら、勤怠詳細に「2026年」と「6月1日」を出すことを確かめる。
     * 一般ユーザーが自分の 2026-06-01 の勤怠（09:00〜18:00、備考 在宅勤務のため）を GET /attendance/{id} で開き、toDetailDate() が日付を分けて、200 で user.user-detail に「2026年」「6月1日」と名前、時刻、備考、送り先が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_values_are_shown(): void
    {
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $attendanceRecord = AttendanceRecord::factory()->create([
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
            'comment' => '在宅勤務のため',
        ]);

        $response = $this->actingAs($user)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('user.user-detail');
        $response->assertSee('value="2026年"', false);
        $response->assertSee('value="6月1日"', false);
        $response->assertSee('value="山田太郎"', false);
        $response->assertSee('name="new_clock_in" value="09:00"', false);
        $response->assertSee('name="new_clock_out" value="18:00"', false);
        $response->assertSee('在宅勤務のため');
        $response->assertSee('action="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }
}
