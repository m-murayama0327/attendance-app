<?php

namespace App\Policies;

use App\Models\Application;
use App\Models\User;

class ApplicationPolicy
{
    /**
     * 申請を見てよいかを返す。申請した人（申請の勤怠の持ち主）か管理者なら見てよい。
     *
     * @param  User  $user  ログインしている人
     * @param  Application  $application  見ようとしている申請
     * @return bool 見てよいなら true
     */
    public function view(User $user, Application $application): bool
    {
        return $user->admin_status || $application->AttendanceRecord->user_id === $user->id;
    }
}
