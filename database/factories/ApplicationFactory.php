<?php

namespace Database\Factories;

use App\Models\Application;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Application>
 */
class ApplicationFactory extends Factory
{
    /**
     * テスト用の修正申請の既定の値を返す。申請する人は、勤怠の持ち主にする。
     *
     * @return array<string, mixed> カラムの名前と既定の値の組
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'user_id' => fn (array $attributes) => AttendanceRecord::find($attributes['attendance_record_id'])->user_id,
            'new_clock_in' => '08:30:00',
            'new_clock_out' => '18:30:00',
            'comment' => '電車遅延のため',
        ];
    }
}
