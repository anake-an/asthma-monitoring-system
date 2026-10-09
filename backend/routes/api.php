<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ConfigController;
use App\Http\Controllers\AuthController;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1');
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
Route::post('/forgot-password', [\App\Http\Controllers\PasswordResetController::class, 'sendResetLinkEmail'])->middleware('throttle:3,1');
Route::post('/reset-password', [\App\Http\Controllers\PasswordResetController::class, 'resetPassword']);

// Protected routes
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', function (Request $request) { return $request->user(); });
    Route::post('/user/password', [AuthController::class, 'updatePassword']);
    Route::delete('/user', [AuthController::class, 'deleteAccount']);
    Route::post('/logout', [AuthController::class, 'logout']);
    
    Route::get('/devices', [\App\Http\Controllers\DeviceController::class, 'getDevices']);
    Route::delete('/devices/{id}', [\App\Http\Controllers\DeviceController::class, 'deleteDevice']);
    Route::post('/devices/generate-token', [\App\Http\Controllers\DeviceController::class, 'generateToken']);
    
    Route::get('/telemetry', [DashboardController::class, 'getTelemetry']);
    Route::get('/cough-events', [DashboardController::class, 'getCoughEvents']);
    Route::post('/cough-events/{id}/verify', [DashboardController::class, 'verifyCoughEvent']);

    Route::get('/config', [ConfigController::class, 'getConfig']);
    Route::post('/config', [ConfigController::class, 'updateConfig']);

    Route::get('/inhaler-status', [DashboardController::class, 'getInhalerStatus']);
    Route::post('/inhaler-logs', [DashboardController::class, 'logManualInhaler']);
    Route::get('/report', [DashboardController::class, 'getWeeklyReport']);

    Route::post('/push-subscribe', [DashboardController::class, 'savePushSubscription']);
    Route::get('/ai/train', [\App\Http\Controllers\AiController::class, 'trainModel']);
    Route::get('/ai/predict', [\App\Http\Controllers\AiController::class, 'getPrediction']);
    Route::get('/vapid-public-key', function () {
        return response()->json(['key' => config('webpush.vapid.public_key')]);
    });

    Route::post('/test-push', function (Request $request) {
        $user = $request->user();
        $event = $user->coughEvents()->latest('recorded_at')->first()
            ?? new \App\Models\CoughEvent(['severity' => \App\Models\CoughEvent::SEVERITY_ALERT]);
        $user->notify(new \App\Notifications\CoughAlertNotification($event));

        return response()->json(['message' => 'Test notification sent']);
    })->middleware('throttle:3,1');
});
