<?php

namespace Tests\Feature\Resources\Views\Admin;

use App\Models\Application;
use App\Models\ProposalBreak;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminApplicationDetailTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 申請の休憩が1行（12:30〜13:30）なら、「〜」の数が出勤・退勤の行と休憩の行の2になることを確かめる。
     * 一般ユーザーの 2026-06-01 の承認待ちの申請の休憩が1行のときに、管理者が GET /stamp_correction_request/approve/{id} を送り、admin-application-detail.blade.php が追加用の空の休憩の行に「〜」を出さずに、200 で「〜」の数が2かを見る。
     *
     * @return void 返す値はない
     */
    public function test_tilde_is_not_shown_in_empty_break_row(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:30:00', 'break_out' => '13:30:00']);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/approve/'.$application->id);

        $response->assertOk();
        $this->assertSame(2, substr_count($response->getContent(), '〜'));
    }
}
