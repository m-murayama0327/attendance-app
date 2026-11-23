<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateUsersTableTest extends TestCase
{
    use RefreshDatabase;

    /**
     * admin_status を渡さずにユーザーを作ると、admin_status が false になることを確かめる。
     * テストから UserFactory で admin_status を渡さずにユーザーを作って fresh() で読み直し、up() が既定の値の false を書いて、admin_status が false かを見る。
     *
     * @return void 返す値はない
     */
    public function test_admin_status_defaults_to_false(): void
    {
        $user = User::factory()->create();

        $user = $user->fresh();

        $this->assertFalse($user->admin_status);
    }
}
