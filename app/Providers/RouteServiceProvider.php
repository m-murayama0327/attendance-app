<?php

namespace App\Providers;

use App\Models\Application;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/attendance';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     * 要件の承認のルートのパラメーターの名前（attendance_correct_request_id）はコントローラーの引数の名前と違うため、この名前のパラメーターを申請として読む決まりを足す。
     *
     * @return void 返す値はない
     */
    public function boot(): void
    {
        Route::model('attendance_correct_request_id', Application::class);

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
