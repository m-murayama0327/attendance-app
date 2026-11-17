<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     * 公開API（api/*）で、勤怠が見つからないときと Policy に止められたときに、要件の文言の JSON を返すようにする。
     *
     * @return void 返す値はない
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        // Laravel は ModelNotFoundException と AuthorizationException をここに渡す前に HTTP の例外に包むため、包んだ後の型で受けて元の型を getPrevious() で確かめる
        $this->renderable(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') || ! $e->getPrevious() instanceof ModelNotFoundException) {
                return null;
            }

            return response()->json(['error' => '勤怠情報が見つかりませんでした。'], 404);
        });

        $this->renderable(function (AccessDeniedHttpException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*') || ! $e->getPrevious() instanceof AuthorizationException) {
                return null;
            }

            return response()->json(['error' => 'この操作を実行する権限がありません。'], 403);
        });
    }

    /**
     * 公開API（api/*）のパスなら、Accept のヘッダーによらずエラーを JSON で返す。他のパスは Laravel の判定に任せる。
     *
     * @param  Request  $request  届いたリクエスト
     * @param  Throwable  $e  起きた例外
     * @return bool JSON で返すなら true
     */
    protected function shouldReturnJson($request, Throwable $e): bool
    {
        return $request->is('api/*') || parent::shouldReturnJson($request, $e);
    }
}
