<?php

namespace Database\Seeders;

use App\Models\AttendanceRecord;
use App\Models\User;
use Carbon\CarbonPeriod;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AttendanceRecordSeeder extends Seeder
{
    // 要件で決まった user1 の当月の内訳。seedIntentionalData() は、この順に今日を除く最初の平日から日を割り当てる
    private const CURRENT_MONTH_PATTERNS = [
        // 通常
        ['days' => 10, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00'],
        // 残業
        ['days' => 3, 'clock_in' => '09:00:00', 'clock_out' => '20:00:00'],
        // 遅刻
        ['days' => 2, 'clock_in' => '09:30:00', 'clock_out' => '18:00:00'],
        // 早退
        ['days' => 1, 'clock_in' => '09:00:00', 'clock_out' => '17:00:00'],
        // 長時間労働
        ['days' => 1, 'clock_in' => '08:00:00', 'clock_out' => '21:00:00'],
    ];

    /**
     * user1 を除く全員について、当月を含む直近6か月の今日より前の平日（土日を除く日で、祝日は含む）の勤怠を作る。
     * 遅刻、早退、長時間労働、出勤と退勤と休憩の打刻漏れ、2回の休憩の日を、乱数を使わずに日付とユーザーで決まる値で混ぜる。
     * user1 には、マイ勤怠レポートの予測値になる勤怠を作る。
     *
     * @return void 返す値はない
     */
    public function run(): void
    {
        // 31日に動かしても月があふれないように、先に当月の1日にしてから5か月を引く
        $weekdays = collect(CarbonPeriod::create(today()->startOfMonth()->subMonths(5), today()->subDay()))
            ->reject(fn (Carbon $date): bool => $date->isWeekend());

        $user1 = User::where('email', 'user1@example.com')->firstOrFail();
        User::whereKeyNot($user1->id)->get()->each(function (User $user) use ($weekdays): void {
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

        $this->seedIntentionalData($user1);
    }

    private function resolveTimes(string $date, int $userId): ?array
    {
        // 乱数を使わずに、動かすたびに同じで、見た目には並びの決まりが分からない値にするため、日付とユーザーの番号から crc32() で数を作る
        // 用途の名前を足すと文字が変わり、別の数になるので、出勤の分、退勤の分、秒、休憩のずれがつられて同じ並びにならない
        $pick = fn (string $purpose, int $count): int => crc32("{$date}|{$userId}|{$purpose}") % $count;
        // 打刻ごとに秒が変わるよう、打刻の用途の名前ごとに0〜59秒を決める
        $makeTime = fn (string $purpose, int $hour, int $offsetMinutes): string => today()->setTime($hour, 0, $pick("{$purpose}_second", 60))->addMinutes($offsetMinutes)->format('H:i:s');

        $clockIn = $makeTime('clock_in', 8, 45 + $pick('clock_in', 16));
        $clockOut = $makeTime('clock_out', 18, $pick('clock_out', 16));
        // 昼休みに入る時刻は日によって前後するので、0〜30 から15を引いた −15〜+15分だけ、12:00 からずらす
        $breakShift = $pick('break', 31) - 15;
        // 休憩の戻りはボタンを押すまでに少し遅れるので、60分の後に0〜5分を足す。勤務が8時間を超える日は休憩を1時間以上取る決まりがあるので、早めない
        $breaks = [['break_in' => $makeTime('break_in', 12, $breakShift), 'break_out' => $makeTime('break_out', 13, $breakShift + $pick('break_out', 6))]];

        $dayHash = crc32("{$date}|{$userId}");

        // 割る数が大きいほど、その種類の日は少なくなる。珍しい日の行を上に置き、2つの行に同時に当たる日が少なくなるよう、割る数はどれも素数にする
        return match (true) {
            // 休憩の打刻漏れ（休憩なし）
            $dayHash % 43 === 0 => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => []],
            // 退勤の打刻漏れ（退勤なし）
            $dayHash % 41 === 0 => ['clock_in' => $clockIn, 'clock_out' => null, 'breaks' => $breaks],
            // 出勤の打刻漏れ。出勤を打刻しなかった日は勤怠の行ができないので、勤怠を作らない
            $dayHash % 37 === 0 => null,
            // 早退（17:30〜17:45 の退勤）
            $dayHash % 31 === 0 => ['clock_in' => $clockIn, 'clock_out' => $makeTime('clock_out', 17, 45 - $pick('early_leave', 16)), 'breaks' => $breaks],
            // 遅刻（9:15〜9:30 の出勤）
            $dayHash % 29 === 0 => ['clock_in' => $makeTime('clock_in', 9, 15 + $pick('late', 16)), 'clock_out' => $clockOut, 'breaks' => $breaks],
            // 休憩2回（30分ずつ。戻りはそれぞれ0〜3分遅れる）
            $dayHash % 17 === 0 => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => [
                ['break_in' => $makeTime('break_in', 12, $breakShift), 'break_out' => $makeTime('break_out', 12, 30 + $breakShift + $pick('break_out', 4))],
                ['break_in' => $makeTime('second_break_in', 15, $breakShift), 'break_out' => $makeTime('second_break_out', 15, 30 + $breakShift + $pick('second_break_out', 4))],
            ]],
            // 長時間労働（20:15〜20:30 の退勤。勤務が10時間を超える）
            $dayHash % 13 === 0 => ['clock_in' => $clockIn, 'clock_out' => $makeTime('clock_out', 20, 15 + $pick('long_work', 16)), 'breaks' => $breaks],
            // 通常の日
            default => ['clock_in' => $clockIn, 'clock_out' => $clockOut, 'breaks' => $breaks],
        };
    }

    /**
     * user1 にマイ勤怠レポートの予測値になる勤怠を作る。
     * 前の5か月は、各月の最初の平日15日を 9:00〜18:00 の通常の日にする。
     * 当月は、今日を除く最初の平日に CURRENT_MONTH_PATTERNS の順で時刻を割り当てる。今日を除くのは user1 で今日の打刻を確かめられるようにするためで、今日より後の日も入る。
     * どの日にも 12:00〜13:00 の休憩を付け、勤務時間とフラグを計算する。
     *
     * @param  User  $user1  意図的データを作るユーザー
     * @return void 返す値はない
     */
    private function seedIntentionalData(User $user1): void
    {
        $firstOfMonth = today()->startOfMonth();

        $pastDays = collect(range(5, 1))
            ->flatMap(fn (int $monthsAgo): Collection => $this->pickWeekdays($firstOfMonth->copy()->subMonths($monthsAgo), 15))
            ->map(fn (Carbon $date): array => ['date' => $date, 'clock_in' => '09:00:00', 'clock_out' => '18:00:00']);

        // 選んだ日と同じ番目どうしで組み合わせられるように、内訳を日数分の行に広げる
        $currentMonthTimes = collect(self::CURRENT_MONTH_PATTERNS)
            ->flatMap(fn (array $pattern): array => array_fill(0, $pattern['days'], $pattern));
        $currentMonthDays = $this->pickWeekdays($firstOfMonth, $currentMonthTimes->count())
            ->map(fn (Carbon $date, int $index): array => ['date' => $date, 'clock_in' => $currentMonthTimes[$index]['clock_in'], 'clock_out' => $currentMonthTimes[$index]['clock_out']]);

        $pastDays->concat($currentMonthDays)->each(function (array $day) use ($user1): void {
            DB::transaction(function () use ($user1, $day): void {
                $attendanceRecord = AttendanceRecord::create([
                    'user_id' => $user1->id,
                    'date' => $day['date']->toDateString(),
                    'clock_in' => $day['clock_in'],
                    'clock_out' => $day['clock_out'],
                ]);
                $attendanceRecord->breaks()->create(['break_in' => '12:00:00', 'break_out' => '13:00:00']);
                $attendanceRecord->recalculate();
            });
        });
    }

    /**
     * 指定した月について、土日と今日を除く平日を月の初めから数えて指定した日数だけ古い順に返す。祝日は平日に含む。
     *
     * @param  Carbon  $firstOfMonth  月の1日
     * @param  int  $count  返す日数
     * @return Collection<int, Carbon> 選んだ日
     */
    private function pickWeekdays(Carbon $firstOfMonth, int $count): Collection
    {
        return collect(CarbonPeriod::create($firstOfMonth, $firstOfMonth->copy()->endOfMonth()))
            ->reject(fn (Carbon $date): bool => $date->isWeekend() || $date->isToday())
            ->take($count)
            ->values();
    }
}
