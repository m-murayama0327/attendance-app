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
     * 全員に、当月を含む直近6か月の今日より前の平日（土日を除く日で、祝日は含む）の勤怠を作る。
     * 遅刻、早退、長時間労働、出勤と退勤と休憩の打刻漏れ、2回の休憩の日を、乱数を使わずに日付とユーザーで決まる値で混ぜる。
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
                $times = $this->resolveTimes($date->toDateString(), $user->id);

                if ($times === null) {
                    return;
                }

                DB::transaction(function () use ($user, $date, $times): void {
                    $attendanceRecord = AttendanceRecord::create([
                        'user_id' => $user->id,
                        'date' => $date->toDateString(),
                        'clock_in' => $times['clock_in'],
                        'clock_out' => $times['clock_out'],
                    ]);
                    $attendanceRecord->breaks()->createMany($times['breaks']);
                    $attendanceRecord->recalculate();
                });
            });
        });
    }

    private function resolveTimes(string $date, int $userId): ?array
    {
        // 乱数を使わずに、動かすたびに同じで、見た目には並びの決まりが分からない値にするため、日付とユーザーの番号から crc32() で数を作る
        // 用途の名前を足すと文字が変わり、別の数になるので、出勤の分、退勤の分、秒、休憩のずれがつられて同じ並びにならない
        $pick = fn (string $purpose, int $count): int => crc32("{$date}|{$userId}|{$purpose}") % $count;
        // 打刻ごとに秒が変わるよう、打刻の用途の名前ごとに0〜59秒を決める
        $at = fn (string $purpose, int $hour, int $offsetMinutes): string => today()->setTime($hour, 0, $pick("{$purpose}_second", 60))->addMinutes($offsetMinutes)->format('H:i:s');

        $clockIn = $at('clock_in', 8, 45 + $pick('clock_in', 16));
        $clockOut = $at('clock_out', 18, $pick('clock_out', 16));
        // 昼休みに入る時刻は日によって前後するので、0〜30 から15を引いた −15〜+15分だけ、12:00 からずらす
        $breakShift = $pick('break', 31) - 15;
        // 休憩の戻りはボタンを押すまでに少し遅れるので、60分の後に0〜5分を足す。勤務が8時間を超える日は休憩を1時間以上取る決まりがあるので、早めない
        $breaks = [['break_in' => $at('break_in', 12, $breakShift), 'break_out' => $at('break_out', 13, $breakShift + $pick('break_out', 6))]];

        $kind = crc32("{$date}|{$userId}");

        // 割る数が大きいほど、その種類の日は少なくなる。珍しい日の行を上に置き、2つの行に同時に当たる日が少なくなるよう、割る数はどれも素数にする
        return match (true) {
            // 休憩の打刻漏れ（休憩なし）
            $kind % 43 === 0 => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => []],
            // 退勤の打刻漏れ（退勤なし）
            $kind % 41 === 0 => ['clock_in' => $clockIn, 'clock_out' => null, 'breaks' => $breaks],
            // 出勤の打刻漏れ。出勤を打刻しなかった日は勤怠の行ができないので、勤怠を作らない
            $kind % 37 === 0 => null,
            // 早退（17:30〜17:45 の退勤）
            $kind % 31 === 0 => ['clock_in' => $clockIn, 'clock_out' => $at('clock_out', 17, 45 - $pick('early_leave', 16)), 'breaks' => $breaks],
            // 遅刻（9:15〜9:30 の出勤）
            $kind % 29 === 0 => ['clock_in' => $at('clock_in', 9, 15 + $pick('late', 16)), 'clock_out' => $clockOut, 'breaks' => $breaks],
            // 休憩2回（30分ずつ。戻りはそれぞれ0〜3分遅れる）
            $kind % 17 === 0 => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => [
                ['break_in' => $at('break_in', 12, $breakShift), 'break_out' => $at('break_out', 12, 30 + $breakShift + $pick('break_out', 4))],
                ['break_in' => $at('second_break_in', 15, $breakShift), 'break_out' => $at('second_break_out', 15, 30 + $breakShift + $pick('second_break_out', 4))],
            ]],
            // 長時間労働（20:15〜20:30 の退勤。勤務が10時間を超える）
            $kind % 13 === 0 => ['clock_in' => $clockIn, 'clock_out' => $at('clock_out', 20, 15 + $pick('long_work', 16)), 'breaks' => $breaks],
            // 通常の日
            default => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => $breaks],
        };
    }
}
