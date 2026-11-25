<?php

namespace Tests\Feature\Resources\Views\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class AdminAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 表示する日（2026-06-02）にその人の勤怠がないなら、名前「山田太郎」の後に中身が空の欄が4つ続けて出ることを確かめる。
     * 一般ユーザー「山田太郎」の 2026-06-02 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/list?date=2026-06-02 を送り、admin-attendance-list.blade.php が @else の行で名前と空の欄を出して、200 で名前の後に空の欄が4つ出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_without_record_is_shown_by_name_only(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?date=2026-06-02');

        $response->assertOk();
        $emptyCell = '<p class="table__description--item"></p>';
        $response->assertSeeInOrder(['山田太郎', $emptyCell, $emptyCell, $emptyCell, $emptyCell], false);
    }

    /**
     * 開く日が 2026-06-03 で表示する日が 2026-06-02（昨日）なら、勤怠がない人の行に「詳細」のリンクが出ることを確かめる。
     * 一般ユーザーの 2026-06-02 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/list?date=2026-06-02 を送り、admin-attendance-list.blade.php が @if で表示する日を今日と比べて、200 で /admin/attendance/staff/{id}/date/2026-06-02 の「詳細」のリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_link_is_shown_until_yesterday(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?date=2026-06-02');

        $response->assertOk();
        $response->assertSee('href="'.url('/admin/attendance/staff/'.$user->id.'/date/2026-06-02').'"', false);
    }

    /**
     * 開く日も表示する日も 2026-06-03（今日）なら、勤怠がない人の行に /admin/attendance/staff/{id}/date/2026-06-03 が出ないことを確かめる。
     * 一般ユーザーの 2026-06-03 の勤怠がないときに、管理者が 2026-06-03 に GET /admin/attendance/list?date=2026-06-03 を送り、admin-attendance-list.blade.php が @if で表示する日を今日と比べて、200 で /admin/attendance/staff/{id}/date/2026-06-03 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_detail_link_is_not_shown_today(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($admin)->get('/admin/attendance/list?date=2026-06-03');

        $response->assertOk();
        $response->assertDontSee(url('/admin/attendance/staff/'.$user->id.'/date/2026-06-03'), false);
    }
}
