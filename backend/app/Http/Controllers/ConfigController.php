<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Alert limits per device (room). ?device_id / device_id picks the room; without it, the user's
 * most recently seen device. Reading needs "view", changing needs "configure" (DevicePolicy).
 */
class ConfigController extends Controller
{
    public function getConfig(Request $request)
    {
        $device = $this->device($request);
        if (!$device) {
            // No device paired yet: show the defaults (nothing is stored until a device exists).
            return response()->json(HardwareConfig::DEFAULTS + ['device_id' => null]);
        }

        return response()->json(HardwareConfig::forDevice($device));
    }

    public function updateConfig(Request $request, Mqtt $mqtt)
    {
        $validated = $request->validate([
            'device_id' => 'nullable|integer',
            'pm25_threshold' => 'numeric|min:0|max:500',
            'temperature_threshold' => 'numeric|min:0|max:60',
            'humidity_threshold' => 'numeric|min:0|max:100',
            'mq135_threshold' => 'numeric|min:0|max:10000', // estimated ppm
            'is_buzzer_muted' => 'boolean',
            'ai_optimization_enabled' => 'boolean',
            'pm25_locked' => 'boolean',
            'temperature_locked' => 'boolean',
            'humidity_locked' => 'boolean',
            'mq135_locked' => 'boolean',
        ]);

        $device = $this->device($request, 'configure');
        if (!$device) {
            return response()->json(['message' => 'Pair a device first: limits are set per device.'], 422);
        }

        $config = HardwareConfig::forDevice($device);
        // The submitted limits are the user's own values (caps); the AI may tighten below them.
        $config->applyUserSettings($validated);
        // The limit values themselves are in limit_changes; this records who saved which settings.
        AuditLog::record($request->user(), 'settings.saved', $device->patient_id, $device->id, array_diff_key($validated, ['device_id' => 0]));

        // Push the new thresholds to this device (retained).
        $synced = 0;
        try {
            $mqtt->publishConfig($device, $config);
            $synced = 1;
        } catch (\Throwable $e) {
            Log::error('Failed to publish config to device', ['device_id' => $device->id, 'error' => $e->getMessage()]);
        }

        return response()->json($config->fresh()->toArray() + ['devices_synced' => $synced]);
    }
}
