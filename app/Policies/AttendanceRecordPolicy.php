<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * 管理者なら、どの判定も調べずに許す。管理者でなければ null を返し、view() などの判定に任せる。
     *
     * @param  User  $user  ログインしている人
     * @return bool|null 管理者なら true、管理者でなければ null
     */
    public function before(User $user): ?bool
    {
        return $user->admin_status ? true : null;
    }

    /**
     * 勤怠を見てよいかを返す。勤怠の持ち主なら見てよい。管理者は before() で先に許している。
     *
     * @param  User  $user  ログインしている人
     * @param  AttendanceRecord  $attendanceRecord  見ようとしている勤怠
     * @return bool 見てよいなら true
     */
    public function view(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $attendanceRecord->user_id === $user->id;
    }

    /**
     * 勤怠を直してよいか（一般ユーザーは修正申請、管理者は直接修正）を返す。勤怠の持ち主なら直してよい。管理者は before() で先に許している。
     *
     * @param  User  $user  ログインしている人
     * @param  AttendanceRecord  $attendanceRecord  直そうとしている勤怠
     * @return bool 直してよいなら true
     */
    public function update(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $attendanceRecord->user_id === $user->id;
    }

    /**
     * 勤怠を消してよいかを返す。勤怠の持ち主なら消してよい。管理者は before() で先に許している。
     *
     * @param  User  $user  ログインしている人
     * @param  AttendanceRecord  $attendanceRecord  消そうとしている勤怠
     * @return bool 消してよいなら true
     */
    public function delete(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $attendanceRecord->user_id === $user->id;
    }
}
