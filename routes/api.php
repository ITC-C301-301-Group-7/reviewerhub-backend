<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningResourceController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\ResourceInteractionController;
use App\Http\Controllers\Api\TranslationController;
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

    // Resource interaction: open one resource (QR links), bookmarks, ratings, comments.
    Route::get('/resources/{resource}', [ResourceInteractionController::class, 'show'])
        ->whereNumber('resource');
    Route::get('/bookmarks', [ResourceInteractionController::class, 'bookmarks']);
    Route::put('/resources/{resource}/bookmark', [ResourceInteractionController::class, 'addBookmark']);
    Route::delete('/resources/{resource}/bookmark', [ResourceInteractionController::class, 'removeBookmark']);
    Route::put('/resources/{resource}/rating', [ResourceInteractionController::class, 'rate']);
    Route::get('/resources/{resource}/comments', [ResourceInteractionController::class, 'comments']);
    Route::post('/resources/{resource}/comments', [ResourceInteractionController::class, 'storeComment'])
        ->middleware('throttle:20,1');
    Route::delete('/comments/{comment}', [ResourceInteractionController::class, 'destroyComment']);

    // OCR text is extracted in the browser; this only translates it.
    Route::post('/translate', [TranslationController::class, 'translate'])
        ->middleware('throttle:60,1');

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
