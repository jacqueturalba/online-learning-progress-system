<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\LearningController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => view('app'));
Route::get('/reset-password/{token}', fn () => view('app'))->middleware('guest')->name('password.reset');
Route::post('/register', [AuthController::class, 'register'])->middleware(['guest', 'throttle:5,1']);
Route::post('/login', [AuthController::class, 'login'])->middleware(['guest', 'throttle:5,1']);
Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth');
Route::get('/api/me', [AuthController::class, 'me'])->middleware('auth');
Route::get('/api/branding', [App\Http\Controllers\BrandingController::class, 'show']);
Route::put('/api/profile/password', [AuthController::class, 'updatePassword'])->middleware(['auth', 'role:student', 'throttle:5,1']);
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware(['guest', 'throttle:5,1']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware(['guest', 'throttle:5,1']);
Route::get('/api/learning', [LearningController::class, 'index'])->middleware(['auth', 'role:student']);
Route::get('/api/lessons/{lesson}/video', [LearningController::class, 'video'])->middleware(['auth', 'role:student'])->name('lessons.video');
Route::put('/api/lessons/{lesson}/progress', [LearningController::class, 'saveProgress'])->middleware(['auth', 'role:student', 'throttle:60,1']);
Route::post('/api/lessons/{lesson}/realizations', [App\Http\Controllers\StudentRealizationController::class, 'store'])->middleware(['auth', 'role:student', 'throttle:10,1']);
Route::prefix('/admin')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/{section?}', fn () => view('app'))
        ->whereIn('section', ['dashboard', 'students', 'categories', 'lessons', 'zoom', 'progress', 'realizations', 'reports', 'settings'])
        ->name('admin.section');
});
Route::prefix('/api/admin')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/dashboard', App\Http\Controllers\AdminDashboardController::class);
    Route::get('/students', [App\Http\Controllers\AdminStudentController::class, 'index']);
    Route::get('/students/{student}', [App\Http\Controllers\AdminStudentController::class, 'show']);
    Route::put('/students/{student}/pace-group', [App\Http\Controllers\AdminStudentController::class, 'updatePaceGroup']);
    Route::put('/students/{student}/password', [App\Http\Controllers\AdminStudentController::class, 'updatePassword']);
    Route::post('/students/{student}/password/reset', [App\Http\Controllers\AdminStudentController::class, 'resetPassword']);
    Route::get('/branding', [App\Http\Controllers\BrandingController::class, 'show']);
    Route::put('/branding', [App\Http\Controllers\BrandingController::class, 'update']);
    Route::put('/pace-groups', [App\Http\Controllers\AdminPaceGroupController::class, 'update']);
    Route::get('/progress', App\Http\Controllers\AdminProgressController::class);
    Route::get('/realizations', App\Http\Controllers\AdminRealizationController::class);
    Route::get('/reports', App\Http\Controllers\AdminReportController::class);
    Route::get('/insights/data', App\Http\Controllers\AdminInsightsController::class);
    Route::post('/transfers', [App\Http\Controllers\AdminTransferAnalysisController::class, 'store']);
    Route::get('/zoom', [App\Http\Controllers\AdminZoomController::class, 'index']);
    Route::post('/zoom/credentials', [App\Http\Controllers\AdminZoomController::class, 'saveCredentials']);
    Route::put('/zoom/assignments/{student}', [App\Http\Controllers\AdminZoomController::class, 'saveAssignment']);
    Route::post('/categories/{category}/restore', [App\Http\Controllers\AdminCatalogController::class, 'restore']);
    Route::apiResource('categories', App\Http\Controllers\AdminCatalogController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/lessons/{lesson}/restore', [App\Http\Controllers\AdminLessonController::class, 'restore']);
    Route::patch('/lessons/{lesson}/move', [App\Http\Controllers\AdminLessonController::class, 'move']);
    Route::apiResource('lessons', App\Http\Controllers\AdminLessonController::class)->only(['index', 'store', 'update', 'destroy']);
});
