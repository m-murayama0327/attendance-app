<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * テスト用の修正申請の既定の値を返す。勤怠と同じ人と日付にするときは、呼ぶ側で user_id と date を渡す。
     *
     * @return array<string, mixed> カラムの名前と既定の値の組
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'date' => today()->subDays(fake()->numberBetween(1, 30))->toDateString(),
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '電車遅延のため',
        ];
    }
}
