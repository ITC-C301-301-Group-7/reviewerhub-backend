<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningResourceController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\TeacherController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

Route::get('/resources', [
    LearningResourceController::class,
    'index'
]);

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/resources', [
        LearningResourceController::class,
        'store'
    ]);

    Route::get('/resources/{resource}/preview', [
        LearningResourceController::class,
        'preview'
    ]);

    Route::get('/resources/{resource}/download', [
        LearningResourceController::class,
        'download'
    ]);

    Route::delete('/resources/{resource}', [
        LearningResourceController::class,
        'destroy'
    ]);

    Route::post('/profile/photo', [
        ProfilePhotoController::class,
        'store'
    ]);

    // Faculty-only: manage reviewers, view student submissions, monitor the community.
    Route::middleware('teacher')->prefix('teacher')->group(function () {
        Route::get('/overview', [TeacherController::class, 'overview']);
        Route::get('/resources', [TeacherController::class, 'resources']);
        Route::patch('/resources/{resource}', [TeacherController::class, 'update']);
        Route::patch('/resources/{resource}/status', [TeacherController::class, 'setStatus']);
        Route::get('/community', [TeacherController::class, 'community']);
    });
});
