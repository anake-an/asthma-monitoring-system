<?php

namespace App\Http\Controllers;

use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ConfigController extends Controller
{
    public function getConfig(Request $request)
    {
        return response()->json(HardwareConfig::forUser($request->user()));
    }

    public function updateConfig(Request $request, Mqtt $mqtt)
    {
        $validated = $request->validate([
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

        $user = $request->user();
        $config = HardwareConfig::forUser($user);
        // The submitted limits are the user's own values (caps); the AI may tighten below them.
        $config->applyUserSettings($validated);

        // Push the new thresholds to every device this account owns (retained).
        $synced = 0;
        foreach ($user->devices as $device) {
            try {
                $mqtt->publishConfig($device, $config);
                $synced++;
            } catch (\Throwable $e) {
                Log::error('Failed to publish config to device', ['device_id' => $device->id, 'error' => $e->getMessage()]);
            }
        }

        return response()->json($config->toArray() + ['devices_synced' => $synced]);
    }
}
