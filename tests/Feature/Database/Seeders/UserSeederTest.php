<?php

namespace Tests\Feature\Database\Seeders;

use App\Models\User;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 空のデータベースで UserSeeder を流すと、要件のログインの情報と役割で user1〜user7 ができることを確かめる。
     * ユーザーがないときに、テストから UserSeeder を流し、run() が決まったユーザーを作って、user1〜user7 ができ、全員がパスワード password でメール認証済み、管理者が user3 だけ、一般ユーザー6人の名前が「西 伶奈」「山田 太郎」「増田 一世」「山本 敬吉」「秋田 朋美」「中西 教夫」の順かを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeder_creates_required_users(): void
    {
        $this->seed(UserSeeder::class);

        $users = User::orderBy('id')->get();
        $this->assertSame(
            ['user1@example.com', 'user2@example.com', 'user3@example.com', 'user4@example.com', 'user5@example.com', 'user6@example.com', 'user7@example.com'],
            $users->pluck('email')->all()
        );
        $this->assertTrue($users->every(fn (User $user): bool => Hash::check('password', $user->password)));
        $this->assertTrue($users->every(fn (User $user): bool => $user->email_verified_at !== null));
        $this->assertSame(['user3@example.com'], $users->where('admin_status', true)->pluck('email')->values()->all());
        $this->assertSame(
            ['西 伶奈', '山田 太郎', '増田 一世', '山本 敬吉', '秋田 朋美', '中西 教夫'],
            $users->where('admin_status', false)->pluck('name')->values()->all()
        );
    }

    /**
     * UserSeeder を流した後にスタッフ一覧を開くと、一般ユーザー6人が Figma と同じ順で出ることを確かめる。
     * UserSeeder を流した後に、管理者（user3）が GET /admin/staff/list を送り、run() が一般ユーザー6人を Figma の順で作って、200 になり、「西 伶奈」「山田 太郎」「増田 一世」「山本 敬吉」「秋田 朋美」「中西 教夫」がその順で出るかを見る。
     *
     * @return void 返す値はない
     */
    public function test_seeded_users_are_listed(): void
    {
        $this->seed(UserSeeder::class);
        $admin = User::where('email', 'user3@example.com')->firstOrFail();

        $response = $this->actingAs($admin)->get('/admin/staff/list');

        $response->assertOk();
        $response->assertSeeInOrder(['西 伶奈', '山田 太郎', '増田 一世', '山本 敬吉', '秋田 朋美', '中西 教夫']);
    }
}
