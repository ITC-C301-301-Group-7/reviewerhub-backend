
<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningResourceController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

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
});
