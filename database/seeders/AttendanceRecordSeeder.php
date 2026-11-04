<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;

class AttendanceRecordSeeder extends Seeder
{
    /**
     * 全員に、当月を含む直近6か月の今日より前の平日（土日を除く日で、祝日は含む）の勤怠を、9:00〜18:00、休憩 12:00〜13:00 で作る。
     *
     * @return void 返す値はない
     */
    public function run(): void
    {
        // 31日に動かしても月があふれないように、先に当月の1日にしてから5か月を引く
        $period = CarbonPeriod::create(today()->startOfMonth()->subMonths(5), today()->subDay());

        foreach (User::all() as $user) {
            foreach ($period as $date) {
                if ($date->isWeekend()) {
                    continue;
                }

                $record = AttendanceRecord::create([
                    'user_id' => $user->id,
                    'date' => $date->toDateString(),
                    'clock_in' => '09:00:00',
                    'clock_out' => '18:00:00',
                ]);
                $record->breaks()->create([
                    'break_in' => '12:00:00',
                    'break_out' => '13:00:00',
                ]);
                $record->recalculate();
            }
        }
    }
}
