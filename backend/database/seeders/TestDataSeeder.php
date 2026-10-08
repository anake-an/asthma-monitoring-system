<?php

namespace Database\Seeders;

use App\Models\CoughEvent;
use App\Models\TelemetryLog;
use Illuminate\Database\Seeder;
use Carbon\Carbon;

class TestDataSeeder extends Seeder
{
    public function run(): void
    {
        // Create a few test cough events
        CoughEvent::create([
            'severity' => 8,
            'recorded_at' => Carbon::now()->subMinutes(5),
        ]);

        CoughEvent::create([
            'severity' => 3,
            'recorded_at' => Carbon::now()->subMinutes(45),
        ]);

        CoughEvent::create([
            'severity' => 6,
            'recorded_at' => Carbon::now()->subHours(2),
        ]);

        // Create some test telemetry logs
        TelemetryLog::create([
            'pm25_level' => 45.2,
            'temperature' => 28.5,
            'humidity' => 65.0,
            'recorded_at' => Carbon::now(),
        ]);
    }
}
