<?php

namespace App\Policies;

use App\Models\AttendanceRecord;
use App\Models\User;

class AttendanceRecordPolicy
{
    /**
     * 勤怠を見てよいかを返す。勤怠の持ち主か管理者なら見てよい。
     *
     * @param  User  $user  ログインしている人
     * @param  AttendanceRecord  $attendanceRecord  見ようとしている勤怠
     * @return bool 見てよいなら true
     */
    public function view(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $user->admin_status || $attendanceRecord->user_id === $user->id;
    }

    /**
     * 勤怠を直してよいか（一般ユーザーは修正申請、管理者は直接修正）を返す。勤怠の持ち主か管理者なら直してよい。
     *
     * @param  User  $user  ログインしている人
     * @param  AttendanceRecord  $attendanceRecord  直そうとしている勤怠
     * @return bool 直してよいなら true
     */
    public function update(User $user, AttendanceRecord $attendanceRecord): bool
    {
        return $user->admin_status || $attendanceRecord->user_id === $user->id;
    }
}
