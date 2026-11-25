<?php

namespace Tests\Feature\Database\Factories;

use App\Models\Application;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ApplicationFactoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * user_id と date を渡して申請を作ると、new_clock_out が 18:30:00 になることを確かめる。
     * テストから一般ユーザーの user_id と date（2026-06-01）を渡して申請のファクトリーで申請を作り、definition() が渡した値で既定の値を置き換えて、new_clock_out が 18:30:00 で user_id と date が渡した値かを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_factory_sets_user_date_and_clock_out(): void
    {
        $user = User::factory()->create(['admin_status' => false]);

        $application = Application::factory()->create(['user_id' => $user->id, 'date' => '2026-06-01']);

        $this->assertDatabaseHas('applications', [
            'id' => $application->id,
            'user_id' => $user->id,
            'date' => '2026-06-01',
            'new_clock_out' => '18:30:00',
        ]);
    }

    /**
     * 2026-06-15 に user_id だけを渡して申請を作ると、date が 2026-05-16〜2026-06-14 の間になり、両端の日も入ることを確かめる。
     * テストから 2026-06-15 に時刻を固定し、乱数の種を 1 に決めて、一般ユーザーの user_id だけを申請のファクトリーの make() に渡して50行作り、definition() が今日から1〜30日前の日を入れて、date が全部 2026-05-16〜2026-06-14 の間で、一番前が 2026-05-16、一番後が 2026-06-14 かを見る。
     *
     * @return void 返す値はない
     */
    public function test_application_factory_date_is_before_today(): void
    {
        $this->travelTo(Carbon::parse('2026-06-15 10:00:00'));
        $user = User::factory()->create(['admin_status' => false]);
        // 種を決めると毎回同じ日付の並びになる。種の 1 は、50行の中に範囲の両端の日が入る並びになるものを選んだ。
        fake()->seed(1);

        $applications = Application::factory()->count(50)->make(['user_id' => $user->id]);

        $this->assertTrue($applications->every(fn (Application $application): bool => $application->date >= '2026-05-16' && $application->date <= '2026-06-14'));
        $this->assertSame('2026-05-16', $applications->min('date'));
        $this->assertSame('2026-06-14', $applications->max('date'));
    }
}
