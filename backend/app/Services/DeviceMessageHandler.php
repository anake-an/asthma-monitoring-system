<?php

namespace App\Services;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\TelemetryLog;
use App\Notifications\CoughAlertNotification;
use App\Support\Mqtt;
use Illuminate\Support\Facades\Log;

/**
 * Turns MQTT messages from devices into database rows and alerts.
 *
 * Identity comes from the topic (respirosync/devices/{token}/...). The broker ACL
 * only lets a device publish under its own token, so the payload is never trusted
 * for identity.
 */
class DeviceMessageHandler
{
    /** Window used for both the cough-cluster rule and the alert cooldown. */
    public const WINDOW_MINUTES = 10;

    /** Coughs inside the window (including the current one) that always raise an alert. */
    public const CLUSTER_COUNT = 3;

    /** A strong detection needs at least this many coughs in the window to alert. */
    public const STRONG_CLUSTER_COUNT = 2;

    /** Pico detection-strength value considered "strong" (heuristic, not a probability). */
    public const STRONG_DETECTION = 0.80;

    public function handle(string $topic, string $message): void
    {
        $parsed = Mqtt::parseTopic($topic);
        if (!$parsed) {
            return;
        }
        [$token, $channel] = $parsed;

        $device = Device::where('device_token', $token)->first();
        if (!$device) {
            Log::warning('MQTT message for unknown device token', ['topic' => $topic]);
            return;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            Log::warning('MQTT payload is not JSON', ['topic' => $topic]);
            return;
        }

        // Online/offline is computed from last_seen_at (Device::OFFLINE_AFTER_SECONDS).
        $device->forceFill(['last_seen_at' => now(), 'status' => 'online'])->save();

        match ($channel) {
            'telemetry' => $this->storeTelemetry($device, $data),
            'events' => $this->handleEvent($device, $data),
        };
    }

    private function storeTelemetry(Device $device, array $data): void
    {
        // PM2.5 is the one reading every telemetry message must carry. Missing or
        // failed sensors are stored as NULL, never replaced with made-up "normal" values.
        $pm25 = self::number($data['pm25_level'] ?? null, 0, 1000);
        if ($pm25 === null) {
            Log::warning('Telemetry rejected: missing/invalid pm25_level', ['device_id' => $device->id]);
            return;
        }

        TelemetryLog::create([
            'device_id' => $device->id,
            'pm25_level' => $pm25,
            'temperature' => self::number($data['temperature'] ?? null, -40, 85),
            'humidity' => self::number($data['humidity'] ?? null, 0, 100),
            'mq135_level' => self::number($data['mq135_level'] ?? null, 0, 10000), // estimated ppm (CO2-equivalent)
            // Set by PHP, not the DB default: the time windows below compare against now() in the
            // app timezone, and the DB clock may run in another one (SQLite's is always UTC).
            'recorded_at' => now(),
        ]);
    }

    private function handleEvent(Device $device, array $data): void
    {
        if (($data['event'] ?? null) !== 'cough') {
            return;
        }

        $confidence = self::number($data['confidence'] ?? null, 0, 1);
        $since = now()->subMinutes(self::WINDOW_MINUTES);

        $recentCoughs = CoughEvent::where('device_id', $device->id)
            ->where('recorded_at', '>=', $since)
            ->count() + 1; // include this one

        $recentAlert = CoughEvent::where('device_id', $device->id)
            ->where('recorded_at', '>=', $since)
            ->where('severity', '>=', CoughEvent::SEVERITY_ALERT)
            ->exists();

        // Alert rule: a cluster of coughs, or a strong detection that is not isolated.
        // A single loud sound (door slam, clap) is logged but never alerts on its own.
        $isAlert = $recentCoughs >= self::CLUSTER_COUNT
            || ($confidence !== null && $confidence >= self::STRONG_DETECTION && $recentCoughs >= self::STRONG_CLUSTER_COUNT);

        $cough = CoughEvent::create([
            'device_id' => $device->id,
            'severity' => $isAlert ? CoughEvent::SEVERITY_ALERT : CoughEvent::SEVERITY_LOGGED,
            'confidence' => $confidence,
            'recorded_at' => now(), // same clock as $since above
        ])->refresh(); // load DB defaults (is_verified, inhaler_used)

        // Cooldown: at most one notification per device per window.
        if ($isAlert && !$recentAlert && $device->user) {
            try {
                $device->user->notify(new CoughAlertNotification($cough, $recentCoughs));
            } catch (\Throwable $e) {
                // An SMTP/push failure must not kill the MQTT worker loop.
                Log::error('Failed to send cough alert', ['device_id' => $device->id, 'error' => $e->getMessage()]);
            }
        }
    }

    private static function number(mixed $value, float $min, float $max): ?float
    {
        if (!is_int($value) && !is_float($value)) {
            return null;
        }
        $f = (float) $value;
        if (is_nan($f) || $f < $min || $f > $max) {
            return null;
        }

        return $f;
    }
}
