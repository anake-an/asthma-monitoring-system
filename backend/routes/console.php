<?php

use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Artisan;

Artisan::command('clear:data', function () {
    \App\Models\TelemetryLog::query()->delete();
    \App\Models\CoughEvent::query()->delete();
    \App\Models\InhalerLog::query()->delete();
    \App\Models\Device::query()->delete();
    \App\Models\HardwareConfig::query()->delete();
    $this->info('All medical and device data cleared! User accounts have been kept.');
})->describe('Clear all data except users');

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

// Option 2: Rolling Window (Every 4 Hours)
// This triggers the Python AI engine to evaluate the last 4 hours of data
// and optimize the environmental thresholds (PM2.5, Temp) if necessary.
Schedule::call(function () {
    try {
        Http::timeout(30)->get('http://ai_engine:8000/train');
    } catch (\Exception $e) {
        // Log silently
    }
})->everyFourHours();
