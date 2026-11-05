<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsAdmin
{
    /**
     * 管理者の画面に入れるのを管理者だけにする。ログインしていない人は管理者のログイン画面へ移し、一般ユーザーには 403 を返す。
     *
     * @param  Request  $request  届いたリクエスト
     * @param  Closure(Request): (Response)  $next  次の処理
     * @return Response 次の処理の応答、管理者のログイン画面への転送、または 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return redirect('/admin/login');
        }

        if (! $user->admin_status) {
            abort(403);
        }

        return $next($request);
    }
}
