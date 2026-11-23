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
            // user_id と date にはユニーク制約がある。そのため、同じ人の勤怠を何行作っても、日付が重ならないようにする。
            // unique() は日時ではなく日数に付ける。日時に付けると、秒が違うだけの同じ日付が通る。
            'date' => today()->subDays(fake()->unique()->numberBetween(1, 365))->toDateString(),
            'clock_in' => '09:00:00',
            'clock_out' => '18:00:00',
        ];
    }
}
