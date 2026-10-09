<?php

namespace App\Console\Commands;

use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Console\Command;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Applies the AI engine's suggested thresholds to each device (room) whose config has
 * "AI optimization" enabled, then pushes the changed limits to that device.
 *
 * Scheduled every 5 minutes in routes/console.php. The AI may only tighten a limit below the
 * user's own value (cap), never above it, and leaves locked limits at the cap. On top of the AI,
 * the missed daily dose rule (HardwareConfig::missedDailyDose) lowers unlocked limits by 15 %
 * until a dose is logged. Devices are only re-published when a limit actually changes.
 */
class AiOptimize extends Command
{
    protected $signature = 'ai:optimize {--user= : Only optimize this user id}';

    protected $description = 'Apply AI threshold suggestions per device and sync them to the devices';

    public function handle(Mqtt $mqtt)
    {
        $configs = HardwareConfig::with('device')
            ->whereNotNull('device_id')
            ->where('ai_optimization_enabled', true)
            ->when($this->option('user'), fn ($q, $id) => $q->where('user_id', $id))
            ->get();

        // The AI model is still per account (phase 4 makes it per device/patient): ask once per
        // account, then apply the answer to each of its rooms with that room's own caps and locks.
        $responses = [];

        foreach ($configs as $config) {
            $device = $config->device;
            $userId = $config->user_id;
            if (!$device) {
                continue;
            }

            // The AI works on the limits without the rule; the rule is applied on top afterwards.
            $ruleWasOn = $config->missed_dose_base !== null;
            $config->withoutMissedDoseRule();
            if (!array_key_exists($userId, $responses)) {
                $responses[$userId] = $this->predictionFor($userId);
            }
            $aiReason = $this->applySuggestion($config, $responses[$userId], $userId);
            $ruleOn = HardwareConfig::missedDailyDose($device->patient_id, now());
            $config->applyMissedDoseRule($ruleOn);

            $limitChanges = $config->pendingLimitChanges();
            $config->save();
            if (!$limitChanges) {
                $this->line("Device {$device->id}: limits unchanged.");
                continue;
            }
            if ($ruleOn !== $ruleWasOn) {
                $config->recordLimitChanges($limitChanges, 'rule', $ruleOn ? HardwareConfig::MISSED_DOSE_ON : HardwareConfig::MISSED_DOSE_OFF);
            } else {
                $config->recordLimitChanges($limitChanges, 'ai', $aiReason);
            }

            try {
                $mqtt->publishConfig($device, $config);
            } catch (\Throwable $e) {
                $this->error("Device {$device->id}: MQTT publish failed: " . $e->getMessage());
            }

            $this->info("Device {$device->id}: thresholds updated.");
        }

        return self::SUCCESS;
    }

    /** The AI engine's answer for an account, or null when it could not be reached. */
    private function predictionFor(int $userId): ?Response
    {
        try {
            return Http::timeout(10)->get(config('services.ai_engine.url') . '/predict', ['user_id' => $userId]);
        } catch (\Throwable $e) {
            $this->error("User {$userId}: AI engine unreachable: " . $e->getMessage());

            return null;
        }
    }

    /**
     * Set the limits the AI allows, in memory. Returns the reason for the log, or null when the
     * engine failed (the limits are then left as they are).
     */
    private function applySuggestion(HardwareConfig $config, ?Response $response, int $userId): ?string
    {
        if (!$response) {
            return null;
        }

        // 400/404: no model or no recent data yet (e.g. right after an AI reset) - a normal state.
        $learning = in_array($response->status(), [400, 404], true);
        $thresholds = $response->successful() ? $response->json('suggested_thresholds') : null;
        if (!$thresholds && !$learning) {
            $this->warn("User {$userId}: no suggestion ({$response->status()}).");

            return null; // engine error: leave the limits as they are
        }

        // Not before the room baseline covers 24 h of readings: until then the user's own values apply
        // (this also undoes changes made before the AI had enough data, e.g. after a reset).
        if (!$learning && $response->json('ready_to_adjust')) {
            // Never above the user's own value, exactly that value when locked, once a day, at most 10 %.
            $config->startAiDayIfDue(now());
            $config->fill($config->limitsFromSuggestion($thresholds));

            return self::reason($response->json('model_stage'), $response->json('probability_of_attack'));
        }
        foreach (HardwareConfig::LIMITS as $name) {
            $config->{"{$name}_threshold"} = $config->capFor($name);
            $config->{"{$name}_day_start"} = $config->capFor($name); // a restore is not an AI change
        }

        return $learning ? 'Back to your limit: the AI is still learning' : 'Back to your limit: less than 24 h of readings';
    }

    /** Plain-language reason for the Activity Log, e.g. "Room unusual (Stage 1)". A normal room moves limits toward its learned limits. */
    public static function reason(?string $stage, $probability): string
    {
        $p = (float) $probability;
        if (str_starts_with((string) $stage, 'Stage 2')) {
            return 'Flare-up risk ' . round($p * 100) . '% (Stage 2 model)';
        }

        if ($p > 0.4) {
            return 'Room ' . ($p > 0.7 ? 'very unusual' : 'unusual') . ' (Stage 1)';
        }

        return "Learned from your room's usual readings (Stage 1)";
    }
}
