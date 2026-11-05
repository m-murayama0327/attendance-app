<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsGeneral
{
    /**
     * 一般ユーザーの画面に入れるのを一般ユーザーだけにする。管理者には 403 を返す。
     *
     * @param  Request  $request  届いたリクエスト
     * @param  Closure(Request): (Response)  $next  次の処理
     * @return Response 次の処理の応答、または 403
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()->admin_status) {
            abort(403);
        }

        return $next($request);
    }
}
