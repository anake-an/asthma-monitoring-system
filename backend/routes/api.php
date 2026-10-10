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
    
    Route::get('/patients', [\App\Http\Controllers\PatientController::class, 'index']);
    Route::post('/patients', [\App\Http\Controllers\PatientController::class, 'store']);
    Route::patch('/patients/{id}', [\App\Http\Controllers\PatientController::class, 'update']);
    Route::delete('/patients/{id}', [\App\Http\Controllers\PatientController::class, 'destroy']);

    // Sharing a child (DESIGN_MULTI_PATIENT.md section 3)
    Route::get('/patients/{id}/members', [\App\Http\Controllers\SharingController::class, 'members']);
    Route::post('/patients/{id}/invites', [\App\Http\Controllers\SharingController::class, 'invite'])->middleware('throttle:10,60');
    Route::patch('/patients/{id}/members/{userId}', [\App\Http\Controllers\SharingController::class, 'updateMember']);
    Route::delete('/patients/{id}/members/{userId}', [\App\Http\Controllers\SharingController::class, 'removeMember']);
    Route::patch('/patients/{id}/alerts', [\App\Http\Controllers\SharingController::class, 'setAlerts']);
    Route::get('/patients/{id}/audit', [\App\Http\Controllers\SharingController::class, 'audit']);
    Route::get('/patients/{id}/export/{kind}', [\App\Http\Controllers\ExportController::class, 'export'])->middleware('throttle:20,1');
    Route::delete('/invites/{inviteId}', [\App\Http\Controllers\SharingController::class, 'cancelInvite']);
    Route::get('/invites/{token}', [\App\Http\Controllers\SharingController::class, 'showInvite'])->middleware('throttle:20,1');
    Route::post('/invites/accept', [\App\Http\Controllers\SharingController::class, 'acceptInvite'])->middleware('throttle:10,1');

    Route::get('/devices', [\App\Http\Controllers\DeviceController::class, 'getDevices']);
    Route::patch('/devices/{id}', [\App\Http\Controllers\DeviceController::class, 'updateDevice']);
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
