<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class UserSeeder extends Seeder
{
    /**
     * 要件の3人（一般ユーザー2人と管理者1人）を、メール認証済みで作る。
     *
     * @return void 返す値はない
     */
    public function run(): void
    {
        $users = [
            ['name' => 'ユーザー1', 'email' => 'user1@example.com', 'admin_status' => false],
            ['name' => 'ユーザー2', 'email' => 'user2@example.com', 'admin_status' => false],
            ['name' => 'ユーザー3', 'email' => 'user3@example.com', 'admin_status' => true],
        ];

        foreach ($users as $user) {
            User::create([
                ...$user,
                'password' => 'password',
                'email_verified_at' => now(),
            ]);
        }
    }
}
