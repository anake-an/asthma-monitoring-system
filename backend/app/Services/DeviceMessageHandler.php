<?php

namespace App\Services;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Models\HardwareConfig;
use App\Models\LimitAlert;
use App\Models\TelemetryLog;
use App\Notifications\CoughAlertNotification;
use App\Notifications\DeviceBackOnlineNotification;
use App\Notifications\LimitAlertNotification;
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
        $wasReportedOffline = $device->offline_alerted_at !== null;
        $device->forceFill(['last_seen_at' => now(), 'status' => 'online', 'offline_alerted_at' => null])->save();
        if ($wasReportedOffline) {
            // devices:offline-alerts told everyone it was offline: say it is back (push only).
            foreach (self::alertRecipients($device) as $user) {
                try {
                    $user->notify(new DeviceBackOnlineNotification($device));
                } catch (\Throwable $e) {
                    Log::error('Failed to send back-online push', ['device_id' => $device->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }

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

        $log = TelemetryLog::create([
            'device_id' => $device->id,
            'pm25_level' => $pm25,
            'temperature' => self::number($data['temperature'] ?? null, -40, 85),
            'humidity' => self::number($data['humidity'] ?? null, 0, 100),
            'mq135_level' => self::number($data['mq135_level'] ?? null, 0, 10000), // estimated ppm (CO2-equivalent)
            // Set by PHP, not the DB default: the time windows below compare against now() in the
            // app timezone, and the DB clock may run in another one (SQLite's is always UTC).
            'recorded_at' => now(),
        ]);

        $this->checkLimits($device, $log);
    }

    /**
     * Alert (email + push, same recipients as cough alerts) when a reading has stayed above the
     * room's limit for LimitAlert::SUSTAIN_MINUTES: every reading in that window is over it (a dip,
     * or a failed sensor, starts the wait again) and the window is covered from its start. The same
     * smoothed values and limits as the device's own alarm. A reminder at most every REPEAT_MINUTES.
     */
    private function checkLimits(Device $device, TelemetryLog $log): void
    {
        $config = HardwareConfig::forDevice($device);
        $now = now();
        $since = $now->copy()->subMinutes(LimitAlert::SUSTAIN_MINUTES);

        foreach (LimitAlert::READINGS as $name => [$column]) {
            $limit = (float) $config->{"{$name}_threshold"};
            if ($log->{$column} === null || $log->{$column} <= $limit) {
                continue; // cheap check first: only a reading over the limit can start an alert
            }
            $window = fn () => TelemetryLog::where('device_id', $device->id)->whereNull('samples')->where('recorded_at', '>=', $since);
            if ($window()->where(fn ($q) => $q->whereNull($column)->orWhere($column, '<=', $limit))->exists()) {
                continue; // not over the limit the whole time
            }
            $first = $window()->min('recorded_at');
            if (!$first || \Carbon\Carbon::parse($first)->gt($since->copy()->addSeconds(10))) {
                continue; // watched for less than the full window (just came online, or just started); a reading comes every 3 s
            }
            $recent = LimitAlert::where('device_id', $device->id)->where('reading', $name)
                ->where('created_at', '>=', $now->copy()->subMinutes(LimitAlert::REPEAT_MINUTES))->exists();
            if ($recent) {
                continue;
            }

            $alert = LimitAlert::create(['device_id' => $device->id, 'reading' => $name, 'value' => (float) $log->{$column},
                'limit_value' => $limit, 'created_at' => $now]);
            foreach (self::alertRecipients($device) as $user) {
                try {
                    $user->notify(new LimitAlertNotification($alert));
                } catch (\Throwable $e) {
                    Log::error('Failed to send limit alert', ['device_id' => $device->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    private function handleEvent(Device $device, array $data): void
    {
        // The device's clock, for networks that block the internet time servers (UDP port 123):
        // it asks over MQTT, which works wherever the device is connected at all.
        if (($data['event'] ?? null) === 'time_request') {
            app(Mqtt::class)->sendTime($device);

            return;
        }
        if (($data['event'] ?? null) === 'hello') {
            $this->handleHello($device, $data);

            return;
        }
        if (($data['event'] ?? null) === 'ota') {
            $this->handleOtaReport($device, $data);

            return;
        }
        if (($data['event'] ?? null) !== 'cough') {
            return;
        }

        $confidence = self::number($data['confidence'] ?? null, 0, 1);
        $since = now()->subMinutes(self::WINDOW_MINUTES);

        $recentCoughs = CoughEvent::where('device_id', $device->id)
            ->where('recorded_at', '>=', $since)
            ->notFalseAlarm() // sounds already marked as false alarms do not make a cluster
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

        // Cooldown: at most one notification per device per window, to every recipient.
        if ($isAlert && !$recentAlert) {
            foreach (self::alertRecipients($device) as $user) {
                try {
                    $user->notify(new CoughAlertNotification($cough, $recentCoughs));
                } catch (\Throwable $e) {
                    // An SMTP/push failure must not kill the MQTT worker loop or skip the others.
                    Log::error('Failed to send cough alert', ['device_id' => $device->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
    }

    /**
     * Sent by the device each time it connects: its firmware version, and boot=true for the first
     * connection after starting. During an update, that tells whether the new firmware runs: the
     * target version means it worked; a fresh start with another version means the update was
     * not installed or the device went back to the old firmware (rollback).
     */
    private function handleHello(Device $device, array $data): void
    {
        $version = $data['firmware'] ?? null;
        if (!is_string($version) || !self::isVersion($version)) {
            return;
        }
        [$version, $build] = FirmwareRelease::normalize($version, self::build($data['build'] ?? null)); // the first builds said "6.2.x"
        $changes = ['firmware_version' => $version, 'firmware_build' => $build];

        // The Edge AI module, as the ESP32 learned it from the module's own "HELLO" (3.3.0 and newer).
        $edgeAi = is_string($data['edge_ai'] ?? null) && self::isVersion($data['edge_ai']) ? $data['edge_ai'] : null;
        if ($edgeAi !== null) {
            $changes += ['edge_ai_version' => $edgeAi, 'edge_ai_build' => self::build($data['edge_ai_build'] ?? null)];
        }

        if ($device->ota_status === 'updating') {
            if (($device->ota_target ?? 'esp32') === 'pico') {
                if ($edgeAi !== null && $edgeAi === $device->ota_target_version) {
                    $changes += ['ota_status' => 'updated', 'ota_error' => null];
                }
            } elseif ($version === $device->ota_target_version) {
                $changes += ['ota_status' => 'updated', 'ota_error' => null];
            } elseif (($data['boot'] ?? false) === true) {
                $changes += ['ota_status' => 'failed', 'ota_error' => "The new firmware did not start; the device runs {$version} again."];
            }
        }
        $device->forceFill($changes)->save();
    }

    private static function isVersion(string $version): bool
    {
        return preg_match('/^\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?$/', $version) === 1;
    }

    private static function build(mixed $build): ?string
    {
        return is_string($build) && preg_match('/^([0-9.]{1,32}|dev)$/', $build) ? $build : null;
    }

    /** {"event":"ota","status":"failed","error":"…"} when the device could not download or check the update. */
    private function handleOtaReport(Device $device, array $data): void
    {
        if (($data['status'] ?? null) !== 'failed' || $device->ota_status !== 'updating') {
            return;
        }
        $error = is_string($data['error'] ?? null) ? mb_substr($data['error'], 0, 160) : 'The device could not install the update.';
        $device->forceFill(['ota_status' => 'failed', 'ota_error' => $error])->save();
    }

    /**
     * Who gets a room's cough alerts: every member of the room's child with alerts on (owners and
     * caregivers by default, viewers if they switched it on), plus the account that paired the
     * device when it is not a member of that child (e.g. a shared room). Read at alert time, so a
     * removed member gets nothing from that moment.
     */
    public static function alertRecipients(Device $device): \Illuminate\Support\Collection
    {
        $users = $device->patient ? $device->patient->alertRecipients() : collect();
        $ownerIsMember = $device->patient && $device->user && $device->patient->roleOf($device->user) !== null;
        if ($device->user && !$ownerIsMember && !$users->contains('id', $device->user->id)) {
            $users->push($device->user);
        }

        return $users->values();
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
