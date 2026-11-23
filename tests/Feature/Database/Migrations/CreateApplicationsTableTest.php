<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateApplicationsTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * is_approved を渡さずに申請を作ると、is_approved が false になることを確かめる。
     * テストから ApplicationFactory で is_approved を渡さずに 2026-06-01 の申請を作って fresh() で読み直し、up() が既定の値の false を書いて、is_approved が false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_is_approved_defaults_to_false(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $application = $application->fresh();

        $this->assertFalse($application->is_approved);
    }

    /**
     * 申請があるユーザーを消すと、QueryException（Cannot delete or update a parent row）になることを確かめる。
     * 2026-06-01 の申請があるときに、テストからユーザーの delete() を呼び、up() が user_id の外部キーの restrictOnDelete() で消す処理を止めて、QueryException になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_user_with_application_cannot_be_deleted(): void
    {
        $user = User::factory()->create(['admin_status' => false]);
        Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertThrows(
            fn () => $user->delete(),
            QueryException::class,
            'Cannot delete or update a parent row'
        );
    }

    /**
     * new_clock_out を渡さずに申請を作ると、QueryException（Field 'new_clock_out' doesn't have a default value）になることを確かめる。
     * テストから new_clock_out を渡さずに 2026-06-01 の申請の Application::create() を呼び、up() が new_clock_out を空にできないカラムにして、QueryException になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_new_clock_out_is_required(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $this->assertThrows(
            fn () => Application::create([
                'user_id' => $user->id,
                'date' => '2026-06-01',
                'new_clock_in' => '09:00:00',
                'comment' => '打刻漏れのため',
            ]),
            QueryException::class,
            "Field 'new_clock_out' doesn't have a default value"
        );
    }
}
