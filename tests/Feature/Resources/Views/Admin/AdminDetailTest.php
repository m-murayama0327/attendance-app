<?php

namespace Tests\Feature\Resources\Views\Admin;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開いた勤怠が保存した勤怠（一般ユーザーの 2026-06-01）なら、フォームの送り先が /attendance/{id} になることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠があるときに、管理者が GET /attendance/{id} を送り、admin-detail.blade.php が $attendanceRecord['id'] があるかで action を分けて、200 で admin.admin-detail のフォームの送り先が /attendance/{id} かを見る。
     *
     * @return void 返す値はない
     */
    public function test_form_posts_to_record(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($admin)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('action="'.url('/attendance/'.$attendanceRecord->id).'"', false);
    }

    /**
     * 開いた勤怠が勤怠がない日の空の勤怠（一般ユーザーの 2026-06-02）なら、フォームの送り先が /admin/attendance/staff/{id}/date/2026-06-02 になることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/staff/{id}/date/2026-06-02 を送り、admin-detail.blade.php が $attendanceRecord['id'] があるかで action を分けて、200 で admin.admin-detail のフォームの送り先が同じ URL かを見る。
     *
     * @return void 返す値はない
     */
    public function test_form_posts_to_staff_date(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id.'/date/2026-06-02');

        $response->assertOk();
        $response->assertViewIs('admin.admin-detail');
        $response->assertSee('action="'.url('/admin/attendance/staff/'.$user->id.'/date/2026-06-02').'"', false);
    }

    /**
     * 勤怠の休憩が1行（12:00〜13:00）なら、「〜」の数が出勤・退勤の行と休憩の行の2になることを確かめる。
     * 一般ユーザーの 2026-06-01 の勤怠の休憩が1行のときに、管理者が GET /attendance/{id} を送り、admin-detail.blade.php が追加用の空の休憩の行に「〜」を出さずに、200 で「〜」の数が2かを見る。
     *
     * @return void 返す値はない
     */
    public function test_tilde_is_not_shown_in_empty_break_row(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $attendanceRecord = AttendanceRecord::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        AttendanceBreak::factory()->create(['attendance_record_id' => $attendanceRecord->id, 'break_in' => '12:00:00', 'break_out' => '13:00:00']);

        $response = $this->actingAs($admin)->get('/attendance/'.$attendanceRecord->id);

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), '〜'));
    }
}
