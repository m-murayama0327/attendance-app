<?php

namespace App\Http\Middleware;

use App\Providers\RouteServiceProvider;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Handle an incoming request.
     * ログイン済みの人がログイン画面などを開いたときに、管理者は勤怠一覧画面（管理者）へ、一般ユーザーは打刻画面へ移す。
     *
     * @param  Request  $request  届いたリクエスト
     * @param  Closure(Request): (Response)  $next  次の処理
     * @param  string  ...$guards  確かめる認証の種類
     * @return Response 次の処理の応答か、役割に合った画面への転送
     */
    public function handle(Request $request, Closure $next, string ...$guards): Response
    {
        $guards = empty($guards) ? [null] : $guards;

        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                if (Auth::guard($guard)->user()->admin_status) {
                    return redirect('/admin/attendance/list');
                }

                return redirect(RouteServiceProvider::HOME);
            }
        }

        return $next($request);
    }
}
