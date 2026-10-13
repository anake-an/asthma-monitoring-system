<?php

namespace App\Services;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\LimitChange;

/**
 * Cough sensitivity per room, from the caregivers' cough reviews: a documented rule, not training.
 *
 * The Edge AI module reports a cough when its cough score reaches the room's cough_threshold, and a
 * possible cough when it is a little under it. The device sends no audio, so the reviews cannot
 * retrain the model; they move this threshold instead:
 *   - possible coughs confirmed as real (at least 2): it missed coughs, -0.05 (more sensitive);
 *   - detections marked false alarm (at least 3, and at least as many as the confirmed ones):
 *     +0.05 (less sensitive);
 *   - detections confirmed as real (at least 3, at most 10 % false alarms): -0.02.
 * A possible cough marked "not a cough" says nothing new (it was rightly left out).
 * Only reviews of coughs recorded since the last change (at most 14 days back) count, so every step
 * needs new evidence; one change per room per 24 h; always between MIN and MAX. Off while the room's
 * AI optimization is off. Each change is a limit_changes row ('cough', in %, source 'rule').
 */
class CoughSensitivity
{
    public const MIN = 0.40;
    public const MAX = 0.85;
    public const STEP = 0.05;
    public const SMALL_STEP = 0.02;
    public const MIN_FALSE_ALARMS = 3;
    public const MIN_CONFIRMED = 3;
    public const MIN_MISSED = 2;
    public const WINDOW_DAYS = 14;

    /**
     * Look at the room's reviews and move its threshold if the rule says so. Returns the saved
     * config when the threshold changed (the caller sends it to the device), otherwise null.
     */
    public static function adjust(Device $device): ?HardwareConfig
    {
        $config = HardwareConfig::forDevice($device);
        if (!$config->ai_optimization_enabled) {
            return null;
        }

        $lastChange = LimitChange::where('device_id', $device->id)->where('limit_name', 'cough')->latest('created_at')->first();
        if ($lastChange && $lastChange->created_at->gt(now()->subDay())) {
            return null;
        }
        $since = now()->subDays(self::WINDOW_DAYS);
        if ($lastChange && $lastChange->created_at->gt($since)) {
            $since = $lastChange->created_at;
        }

        $reviews = CoughEvent::where('device_id', $device->id)
            ->where('recorded_at', '>=', $since)
            ->whereNotNull('is_verified')
            ->get(['is_verified', 'is_possible']);
        $missed = $reviews->filter(fn ($r) => $r->is_possible && $r->is_verified)->count();
        $detected = $reviews->reject(fn ($r) => $r->is_possible);
        $falseAlarms = $detected->filter(fn ($r) => !$r->is_verified)->count();
        $confirmed = $detected->count() - $falseAlarms;

        $old = round((float) ($config->cough_threshold ?? HardwareConfig::DEFAULTS['cough_threshold']), 2);
        if ($missed >= self::MIN_MISSED) {
            $new = max(self::MIN, round($old - self::STEP, 2));
            $reason = "From your reviews: {$missed} possible coughs were real, so the room is more sensitive";
        } elseif ($falseAlarms >= self::MIN_FALSE_ALARMS && $falseAlarms >= $confirmed) {
            $new = min(self::MAX, round($old + self::STEP, 2));
            $reason = "From your reviews: {$falseAlarms} of {$detected->count()} coughs were false alarms, so the room is less sensitive";
        } elseif ($confirmed >= self::MIN_CONFIRMED && $falseAlarms <= 0.1 * $detected->count()) {
            $new = max(self::MIN, round($old - self::SMALL_STEP, 2));
            $reason = "From your reviews: {$confirmed} real coughs confirmed, so the room is a little more sensitive";
        } else {
            return null;
        }
        if ($new == $old) {
            return null;
        }

        $config->cough_threshold = $new;
        $config->save();
        // In % in the log, like the other limits' plain numbers: "Cough score needed 60% -> 65%".
        $config->recordLimitChanges(['cough' => [round($old * 100), round($new * 100)]], 'rule', $reason);

        return $config;
    }
}
