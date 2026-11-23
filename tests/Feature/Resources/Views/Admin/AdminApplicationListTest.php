<?php

namespace Tests\Feature\Resources\Views\Admin;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminApplicationListTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 申請の date がその日の勤怠がない日（2026-06-01）でも、「2026/06/01」が出ることを確かめる。
     * 一般ユーザー「山田太郎」の勤怠がない 2026-06-01 の承認待ちの申請があるときに、管理者が GET /stamp_correction_request/list を送り、admin-application-list.blade.php が申請の date を「Y/m/d」で出して、200 で名前の後に「2026/06/01」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_for_day_without_record_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $response->assertSeeInOrder(['山田太郎', '2026/06/01']);
    }

    /**
     * 承認済みの申請の date がその日の勤怠がない日（2026-06-01）でも、承認済みの表に「2026/06/01」が出ることを確かめる。
     * 一般ユーザー「山田太郎」の勤怠がない 2026-06-01 の承認済みの申請があるときに、管理者が GET /stamp_correction_request/list を送り、admin-application-list.blade.php が申請の date を「Y/m/d」で出して、200 で承認済みの表で名前の後に「2026/06/01」が出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_approved_application_for_day_without_record_is_shown(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false, 'name' => '山田太郎']);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01', 'is_approved' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/list');

        $response->assertOk();
        $approvedTable = Str::after($response->getContent(), 'id="content2"');
        $this->assertMatchesRegularExpression('#山田太郎.*2026/06/01#s', $approvedTable);
    }
}
