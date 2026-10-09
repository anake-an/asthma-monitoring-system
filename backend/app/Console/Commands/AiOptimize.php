<?php

namespace App\Console\Commands;

use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Applies the AI engine's suggested thresholds to each account that has
 * "AI optimization" enabled, then pushes them to that account's devices.
 *
 * Scheduled every 5 minutes in routes/console.php. The AI may only tighten a limit below the
 * user's own value (cap), never above it, and leaves locked limits at the cap. Devices are
 * only re-published when a limit actually changes.
 */
class AiOptimize extends Command
{
    protected $signature = 'ai:optimize {--user= : Only optimize this user id}';

    protected $description = 'Apply per-user AI threshold suggestions and sync them to devices';

    public function handle(Mqtt $mqtt)
    {
        $configs = HardwareConfig::with('user.devices')
            ->whereNotNull('user_id')
            ->where('ai_optimization_enabled', true)
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->get();

        foreach ($configs as $config) {
            $userId = $config->user_id;

            try {
                $response = Http::timeout(10)->get(config('services.ai_engine.url') . '/predict', ['user_id' => $userId]);
            } catch (\Throwable $e) {
                $this->error("User {$userId}: AI engine unreachable: " . $e->getMessage());
                continue;
            }

            $thresholds = $response->successful() ? $response->json('suggested_thresholds') : null;
            if (!$thresholds) {
                $this->warn("User {$userId}: no suggestion ({$response->status()}).");
                continue;
            }

            // Not before the room baseline covers 24 h of readings.
            if (!$response->json('ready_to_adjust')) {
                $this->line("User {$userId}: not enough data to adjust limits yet ({$response->json('training_windows')} windows).");
                continue;
            }

            // Never above the user's own value, exactly that value when locked, at most 10 % per day.
            $config->startAiDayIfDue(now());
            $config->fill($config->limitsFromSuggestion($thresholds));
            $limitsChanged = $config->isDirty(array_map(fn ($n) => "{$n}_threshold", HardwareConfig::LIMITS));
            $config->save();
            if (!$limitsChanged) {
                $this->line("User {$userId}: limits unchanged.");
                continue;
            }

            foreach ($config->user->devices as $device) {
                try {
                    $mqtt->publishConfig($device, $config);
                } catch (\Throwable $e) {
                    $this->error("Device {$device->id}: MQTT publish failed: " . $e->getMessage());
                }
            }

            $this->info("User {$userId}: thresholds updated (risk " . $response->json('probability_of_attack') . ').');
        }

        return self::SUCCESS;
    }
}
