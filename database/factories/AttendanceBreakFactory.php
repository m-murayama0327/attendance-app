<?php

namespace Database\Factories;

use App\Models\AttendanceBreak;
use App\Models\AttendanceRecord;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceBreak>
 */
class AttendanceBreakFactory extends Factory
{
    /**
     * テスト用の休憩の既定の値を返す。
     *
     * @return array<string, mixed> カラムの名前と既定の値の組
     */
    public function definition(): array
    {
        return [
            'attendance_record_id' => AttendanceRecord::factory(),
            'break_in' => '12:00:00',
            'break_out' => '13:00:00',
        ];
    }
}
