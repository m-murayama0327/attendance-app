<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
        $weekdays = collect(CarbonPeriod::create(today()->startOfMonth()->subMonths(5), today()->subDay()))
            ->reject(fn (Carbon $date): bool => $date->isWeekend());

        User::all()->each(function (User $user) use ($weekdays): void {
            $weekdays->each(function (Carbon $date) use ($user): void {
                DB::transaction(function () use ($user, $date): void {
                    $attendanceRecord = AttendanceRecord::create([
                        'user_id' => $user->id,
                        'date' => $date->toDateString(),
                        'clock_in' => '09:00:00',
                        'clock_out' => '18:00:00',
                    ]);
                    $attendanceRecord->breaks()->create([
                        'break_in' => '12:00:00',
                        'break_out' => '13:00:00',
                    ]);
                    $attendanceRecord->recalculate();
                });
            });
        });
    }
}
