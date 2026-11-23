<?php

namespace Tests\Feature\Resources\Views\Admin;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く日が 2026-06-03 で行の日付が 2026-06-02（昨日）なら、勤怠がない日の行に「詳細」のリンクが出ることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/staff/{id} を送り、staff-attendance-list.blade.php が @elseif で行の日付を今日と比べて、200 で /admin/attendance/staff/{id}/date/2026-06-02 の「詳細」のリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_link_is_shown_until_yesterday(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id);

        $response->assertOk();
        $response->assertSee('href="'.url('/admin/attendance/staff/'.$user->id.'/date/2026-06-02').'"', false);
    }

    /**
     * 開く日も行の日付も 2026-06-03（今日）なら、勤怠がない日の行に /admin/attendance/staff/{id}/date/2026-06-03 が出ないことを確かめる。
     * 一般ユーザーの 2026-06-03 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/staff/{id} を送り、staff-attendance-list.blade.php が @elseif で行の日付を今日と比べて、200 で /admin/attendance/staff/{id}/date/2026-06-03 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_link_is_not_shown_today(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/staff/'.$user->id);

        $response->assertOk();
        $response->assertDontSee(url('/admin/attendance/staff/'.$user->id.'/date/2026-06-03'), false);
    }
}
