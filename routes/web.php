<?php

use App\Http\Controllers\Admin\AttendanceController as AdminAttendanceController;
use App\Http\Controllers\Admin\StaffController;
use App\Http\Controllers\ApplicationController;
use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\HomeController;
use App\Models\AttendanceRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::get('/', HomeController::class);
Route::get('/admin/login', [AuthenticatedSessionController::class, 'create'])->middleware('guest:web');
Route::post('/admin/login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest:web', 'throttle:login']);
Route::post('/admin/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('admin');
Route::middleware('admin')->group(function () {
    Route::get('/admin/attendance/list', [AdminAttendanceController::class, 'index']);
    Route::get('/admin/attendance/staff/{id}', [AdminAttendanceController::class, 'staff'])->whereNumber('id');
    Route::get('/admin/attendance/{attendanceRecord}', [AdminAttendanceController::class, 'show'])->whereNumber('attendanceRecord');
    Route::get('/admin/staff/list', [StaffController::class, 'index']);
});
Route::middleware(['auth', 'general'])->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::post('/attendance', [AttendanceController::class, 'store']);
    Route::get('/attendance/list', [AttendanceController::class, 'list']);
    Route::get('/attendance/detail/{attendanceRecord}', [AttendanceController::class, 'show'])->whereNumber('attendanceRecord');
    Route::get('/application/{application}', [ApplicationController::class, 'show'])->whereNumber('application');
});
Route::middleware('auth')->group(function () {
    // 提供された Blade が一般ユーザーと管理者で同じパスを使うので、ログインしている人の役割を見て呼ぶコントローラーを分ける
    Route::get('/attendance/{attendanceRecord}', function (Request $request, AttendanceRecord $attendanceRecord): mixed {
        $controller = $request->user()->admin_status ? AdminAttendanceController::class : AttendanceController::class;

        return app()->call([app($controller), 'show'], ['attendanceRecord' => $attendanceRecord]);
    })->whereNumber('attendanceRecord');
    Route::post('/attendance/{attendanceRecord}', function (Request $request, AttendanceRecord $attendanceRecord): mixed {
        $controller = $request->user()->admin_status ? AdminAttendanceController::class : AttendanceController::class;

        return app()->call([app($controller), 'update'], ['attendanceRecord' => $attendanceRecord]);
    })->whereNumber('attendanceRecord');
    Route::get('/stamp_correction_request/list', [ApplicationController::class, 'index']);
});
