<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 要件の3人（一般ユーザー2人と管理者1人）に画面の確かめ用の一般ユーザー4人を足した7人を、メール認証済みで作る。
     *
     * @return void 返す値はない
     */
    public function run(): void
    {
        collect([
            ['name' => '西 伶奈', 'email' => 'user1@example.com', 'admin_status' => false],
            ['name' => '山田 太郎', 'email' => 'user2@example.com', 'admin_status' => false],
            ['name' => '佐藤 健', 'email' => 'user3@example.com', 'admin_status' => true],
            ['name' => '増田 一世', 'email' => 'user4@example.com', 'admin_status' => false],
            ['name' => '山本 敬吉', 'email' => 'user5@example.com', 'admin_status' => false],
            ['name' => '秋田 朋美', 'email' => 'user6@example.com', 'admin_status' => false],
            ['name' => '中西 教夫', 'email' => 'user7@example.com', 'admin_status' => false],
        ])->each(function (array $attributes): void {
            User::create([
                ...$attributes,
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
        });
    }
}
