<?php

namespace Database\Factories;

use App\Models\AttendanceRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /**
     * テスト用の勤怠の既定の値を返す。計算した値とフラグは入れない。
     *
     * @return array<string, mixed> カラムの名前と既定の値の組
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            // 同じ人で何行作っても user_id と date のユニーク制約に当たらないように、日付は重ならない値にする
            'date' => fake()->unique()->dateTimeBetween('-1 year', 'yesterday')->format('Y-m-d'),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ];
    }
}
