<?php

use Illuminate\Support\Facades\Schedule;

// Every 4 hours, retrain the AI: a room model per device, a risk model per child
// (DESIGN_MULTI_PATIENT.md section 5). Run "php artisan ai:train" by hand after a reset.
Schedule::command('ai:train')->everyFourHours()->withoutOverlapping();

// Every 5 minutes, apply each room's AI-suggested limits and push changes to that device. Only rooms
// with "AI optimization" enabled; the AI may tighten a limit below the user's own value (cap) but
// never raise it above, never touches locked limits, and changes each limit at most once a day.
Schedule::command('ai:optimize')->everyFiveMinutes()->withoutOverlapping();

// Nightly, thin out old sensor readings: every reading for 7 days, then one 10-minute average per
// device, deleted after a year (App\Console\Commands\TelemetryPrune).
Schedule::command('telemetry:prune')->dailyAt('03:30')->withoutOverlapping();
