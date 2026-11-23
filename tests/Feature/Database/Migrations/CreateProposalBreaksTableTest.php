<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\Application;
use App\Models\ProposalBreak;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateProposalBreaksTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 申請の休憩がある申請を消すと、申請の休憩が proposal_breaks からなくなることを確かめる。
     * 申請に申請の休憩 12:30〜13:30 があるときに、テストから申請の delete() を呼び、up() が application_id の外部キーの cascadeOnDelete() で申請の休憩も消して、申請の休憩が proposal_breaks にないかを見る。
     *
     * @return void 返す値はない
     */
    public function test_deleting_application_deletes_proposal_breaks(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);
        $proposalBreak = ProposalBreak::factory()->create(['application_id' => $application->id, 'break_in' => '12:30:00', 'break_out' => '13:30:00']);

        $application->delete();

        $this->assertDatabaseMissing('proposal_breaks', ['id' => $proposalBreak->id]);
    }

    /**
     * break_out を渡さずに申請の休憩を作ると、QueryException（Field 'break_out' doesn't have a default value）になることを確かめる。
     * テストから application_id と break_in（12:30:00）だけを渡して ProposalBreak::create() を呼び、up() が break_out を空にできないカラムにして、QueryException になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_proposal_break_out_is_required(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertThrows(
            fn () => ProposalBreak::create(['application_id' => $application->id, 'break_in' => '12:30:00']),
            QueryException::class,
            "Field 'break_out' doesn't have a default value"
        );
    }
}
