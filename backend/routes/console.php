<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// Every 4 hours, retrain each account's model on that account's own data.
Schedule::call(function () {
    User::has('devices')->pluck('id')->each(function ($userId) {
        try {
            Http::timeout(30)->get(config('services.ai_engine.url') . '/train', ['user_id' => $userId]);
        } catch (\Throwable $e) {
            Log::warning('Scheduled AI training failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
        }
    });
})->everyFourHours()->name('ai-train-per-user')->withoutOverlapping();

// Every 5 minutes, apply each account's AI-suggested thresholds and push them to that
// account's devices. Only affects accounts with "AI optimization" enabled; while it is on,
// manual threshold edits are overwritten on the next run.
Schedule::command('ai:optimize')->everyFiveMinutes()->withoutOverlapping();
