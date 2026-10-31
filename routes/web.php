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
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware(['guest', 'throttle:5,1']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware(['guest', 'throttle:5,1']);
Route::get('/api/learning', [LearningController::class, 'index'])->middleware(['auth', 'role:student']);
Route::put('/api/lessons/{lesson}/progress', [LearningController::class, 'saveProgress'])->middleware(['auth', 'role:student']);
Route::prefix('/api/admin')->middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/students', [LearningController::class, 'students']);
    Route::get('/progress', [LearningController::class, 'adminProgress']);
    Route::apiResource('categories', App\Http\Controllers\AdminCatalogController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::apiResource('lessons', App\Http\Controllers\AdminLessonController::class)->only(['store', 'update', 'destroy']);
});
