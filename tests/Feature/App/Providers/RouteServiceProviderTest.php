<?php

namespace Tests\Feature\App\Providers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RouteServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * URL の番号が申請のない番号（999）なら、404 になることを確かめる。
     * 申請が1件もないときに、管理者が GET /stamp_correction_request/approve/999 を開き、boot() が attendance_correct_request_id を申請として読んで、404 になるかを見る。
     *
     * @return void 返す値はない
     */
    public function test_missing_application_returns_not_found(): void
    {
        $admin = User::factory()->create(['admin_status' => true]);

        $response = $this->actingAs($admin)->get('/stamp_correction_request/approve/999');

        $response->assertNotFound();
    }
}
