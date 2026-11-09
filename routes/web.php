<?php

use App\Http\Controllers\AttendanceController;
use App\Http\Controllers\HomeController;
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
Route::middleware(['auth', 'general'])->group(function () {
    Route::get('/attendance', [AttendanceController::class, 'index']);
    Route::post('/attendance', [AttendanceController::class, 'store']);
    Route::get('/attendance/list', [AttendanceController::class, 'list']);
    Route::get('/attendance/detail/{attendanceRecord}', [AttendanceController::class, 'show'])->whereNumber('attendanceRecord');
});
Route::middleware('auth')->group(function () {
    Route::get('/attendance/{attendanceRecord}', [AttendanceController::class, 'show'])->whereNumber('attendanceRecord');
    Route::post('/attendance/{attendanceRecord}', [AttendanceController::class, 'update'])->whereNumber('attendanceRecord');
});
