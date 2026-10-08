<?php

namespace App\Http\Controllers;

use App\Models\HardwareConfig;
use Illuminate\Http\Request;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class ConfigController
{
    public function getConfig()
    {
        $config = HardwareConfig::first();
        if (!$config) {
            $config = HardwareConfig::create([
                'pm25_threshold' => 35.0,
                'temperature_threshold' => 35.0,
                'humidity_threshold' => 60.0,
                'mq135_threshold' => 300.0,
                'is_buzzer_muted' => false,
                'ai_optimization_enabled' => true,
            ]);
        }
        return response()->json($config);
    }

    public function updateConfig(Request $request)
    {
        $validated = $request->validate([
            'pm25_threshold' => 'numeric',
            'temperature_threshold' => 'numeric',
            'humidity_threshold' => 'numeric',
            'mq135_threshold' => 'numeric',
            'is_buzzer_muted' => 'boolean',
            'ai_optimization_enabled' => 'boolean',
        ]);

        $config = HardwareConfig::first();
        if ($config) {
            $config->update($validated);
        } else {
            $config = HardwareConfig::create($validated);
        }

        // Publish to MQTT
        try {
            $server   = env('MQTT_HOST', 'mqtt');
            $port     = env('MQTT_PORT', 1883);
            $clientId = 'laravel_publisher_' . uniqid();

            $mqtt = new MqttClient($server, $port, $clientId);
            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(10)
                ->setUseTls(false)
                ->setTlsSelfSignedAllowed(true);

            $mqtt->connect($settings, true);

            $payload = json_encode([
                'pm25_threshold' => $config->pm25_threshold,
                'temperature_threshold' => $config->temperature_threshold,
                'humidity_threshold' => $config->humidity_threshold,
                'mq135_threshold' => $config->mq135_threshold,
                'is_buzzer_muted' => $config->is_buzzer_muted,
                'ai_optimization_enabled' => $config->ai_optimization_enabled,
            ]);

            $mqtt->publish('asthma/config', $payload, 0);
            $mqtt->disconnect();
        } catch (\Exception $e) {
            \Log::error("Failed to publish config to MQTT: " . $e->getMessage());
            // Optionally, return error response if MQTT is critical
        }

        return response()->json($config);
    }
}
