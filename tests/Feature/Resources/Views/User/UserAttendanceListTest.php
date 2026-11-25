<?php

namespace Tests\Feature\Resources\Views\User;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class UserAttendanceListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 開く日が 2026-06-03 で行の日付が 2026-06-02（昨日）なら、勤怠がない日の行に「詳細」のリンクが出ることを確かめる。
     * 自分の 2026-06-02 の勤怠がないときに、一般ユーザーが 2026-06-03 に GET /attendance/list を送り、user-attendance-list.blade.php が @elseif で行の日付を今日と比べて、200 で /attendance/date/2026-06-02 の「詳細」のリンクが出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_day_without_record_has_detail_link_until_yesterday(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertOk();
        $response->assertSee('href="'.url('/attendance/date/2026-06-02').'"', false);
    }

    /**
     * 開く日も行の日付も 2026-06-03（今日）なら、勤怠がない日の行に /attendance/date/2026-06-03 が出ないことを確かめる。
     * 自分の 2026-06-03 の勤怠がないときに、一般ユーザーが 2026-06-03 に GET /attendance/list を送り、user-attendance-list.blade.php が @elseif で行の日付を今日と比べて、200 で /attendance/date/2026-06-03 が出ないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_today_has_no_detail_link(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $this->travelTo(Carbon::parse('2026-06-03 10:00:00'));

        $response = $this->actingAs($user)->get('/attendance/list');

        $response->assertOk();
        $response->assertDontSee(url('/attendance/date/2026-06-03'), false);
    }
}
