<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningResourceController;
use App\Http\Controllers\Api\LeaderboardController;
use App\Http\Controllers\Api\PasswordResetController;
use App\Http\Controllers\Api\ProfilePhotoController;
use App\Http\Controllers\Api\ResourceInteractionController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\TeacherController;
use App\Http\Controllers\Api\VideoResourceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register'])
    ->middleware('throttle:register');
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login');

// Password reset (public, rate limited). Always answers the same way for /forgot-password.
Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
    ->middleware('throttle:password-reset');
Route::post('/reset-password', [PasswordResetController::class, 'reset'])
    ->middleware('throttle:password-reset');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    // The reviewer list needs a signed-in account (it names the uploaders).
    Route::get('/resources', [
        LearningResourceController::class,
        'index'
    ]);

    // Leaderboard for the dashboard. Keep it above /resources/{resource}.
    Route::get('/resources/top', [LearningResourceController::class, 'top']);

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
    Route::delete('/resources/{resource}/rating', [ResourceInteractionController::class, 'unrate']);
    Route::put('/resources/{resource}/like', [ResourceInteractionController::class, 'like']);
    Route::delete('/resources/{resource}/like', [ResourceInteractionController::class, 'unlike']);
    Route::get('/resources/{resource}/comments', [ResourceInteractionController::class, 'comments']);
    Route::post('/resources/{resource}/comments', [ResourceInteractionController::class, 'storeComment'])
        ->middleware('throttle:20,1');
    Route::delete('/comments/{comment}', [ResourceInteractionController::class, 'destroyComment']);

    // Video reviewers shared as YouTube / Vimeo links.
    Route::get('/videos', [VideoResourceController::class, 'index']);
    Route::post('/videos', [VideoResourceController::class, 'store'])
        ->middleware('throttle:20,1');
    Route::delete('/videos/{video}', [VideoResourceController::class, 'destroy'])
        ->whereNumber('video');

    // Contributor leaderboard (This Month / This Semester / All-Time).
    Route::get('/leaderboard', [LeaderboardController::class, 'index']);

    // The signed-in user's own class schedule (add / edit / delete).
    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::post('/schedule', [ScheduleController::class, 'store']);
    Route::put('/schedule/{entry}', [ScheduleController::class, 'update'])->whereNumber('entry');
    Route::delete('/schedule/{entry}', [ScheduleController::class, 'destroy'])->whereNumber('entry');

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
        // Keep the bulk route above /resources/{resource} so "bulk-status" is not read as an id.
        Route::patch('/resources/bulk-status', [TeacherController::class, 'bulkStatus']);
        Route::patch('/resources/{resource}', [TeacherController::class, 'update'])->whereNumber('resource');
        Route::patch('/resources/{resource}/status', [TeacherController::class, 'setStatus'])->whereNumber('resource');
        Route::get('/community', [TeacherController::class, 'community']);
    });
});
