<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\Patient;
use App\Support\AiEngine;
use Illuminate\Console\Command;

/**
 * Retrain the AI: a room model for every device, then a risk model for every child with a room.
 * Scheduled every 4 hours (routes/console.php); run it by hand after a deploy or an AI reset.
 * "Not enough data yet" answers are normal while a room or child is new.
 */
class AiTrain extends Command
{
    protected $signature = 'ai:train {--device= : Only this device\'s room model} {--patient= : Only this patient\'s risk model}';

    protected $description = 'Train the room model of each device and the risk model of each patient';

    public function handle(AiEngine $ai)
    {
        $device = $this->option('device');
        $patient = $this->option('patient');
        $all = !$device && !$patient;

        $deviceIds = $all ? Device::pluck('id') : ($device ? collect([(int) $device]) : collect());
        foreach ($deviceIds as $id) {
            $this->report("Room model, device {$id}", fn () => $ai->trainRoom($id));
        }

        $patientIds = $all ? Patient::has('devices')->pluck('id') : ($patient ? collect([(int) $patient]) : collect());
        foreach ($patientIds as $id) {
            $this->report("Risk model, patient {$id}", fn () => $ai->trainRisk($id));
        }

        return self::SUCCESS;
    }

    private function report(string $what, callable $call): void
    {
        try {
            $response = $call();
            $message = $response->json('message') ?? $response->json('detail') ?? $response->status();
            $response->successful() ? $this->info("{$what}: {$message}") : $this->line("{$what}: {$message}");
        } catch (\Throwable $e) {
            $this->error("{$what}: AI engine unreachable: " . $e->getMessage());
        }
    }
}
