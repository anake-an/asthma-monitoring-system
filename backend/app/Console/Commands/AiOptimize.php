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

            // 400/404: no model or no recent data yet (e.g. right after an AI reset) - a normal state.
            $learning = in_array($response->status(), [400, 404], true);
            $thresholds = $response->successful() ? $response->json('suggested_thresholds') : null;
            if (!$thresholds && !$learning) {
                $this->warn("User {$userId}: no suggestion ({$response->status()}).");
                continue; // engine error: leave the limits as they are
            }

            // Not before the room baseline covers 24 h of readings: until then the user's own values apply
            // (this also undoes changes made before the AI had enough data, e.g. after a reset).
            $ready = !$learning && (bool) $response->json('ready_to_adjust');
            if ($ready) {
                // Never above the user's own value, exactly that value when locked, at most 10 % per day.
                $config->startAiDayIfDue(now());
                $config->fill($config->limitsFromSuggestion($thresholds));
            } else {
                foreach (HardwareConfig::LIMITS as $name) {
                    $config->{"{$name}_threshold"} = $config->capFor($name);
                    $config->{"{$name}_day_start"} = $config->capFor($name); // a restore is not an AI change
                }
            }
            $limitChanges = $config->pendingLimitChanges();
            $config->save();
            if (!$limitChanges) {
                $this->line("User {$userId}: limits unchanged.");
                continue;
            }
            $config->recordLimitChanges($limitChanges, 'ai', $ready
                ? self::reason($response->json('model_stage'), $response->json('probability_of_attack'))
                : ($learning ? 'Back to your limit: the AI is still learning' : 'Back to your limit: less than 24 h of readings'));

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

    /** Plain-language reason for the Activity Log, e.g. "Room unusual (Stage 1)". */
    public static function reason(?string $stage, $probability): string
    {
        $p = (float) $probability;
        if (str_starts_with((string) $stage, 'Stage 2')) {
            return 'Flare-up risk ' . round($p * 100) . '% (Stage 2 model)';
        }

        return 'Room ' . ($p > 0.7 ? 'very unusual' : ($p > 0.4 ? 'unusual' : 'normal')) . ' (Stage 1)';
    }
}
