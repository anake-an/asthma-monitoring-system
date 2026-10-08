<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use App\Models\HardwareConfig;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;

class AiOptimize extends Command
{
    protected $signature = 'ai:optimize';
    protected $description = 'Fetch AI predictions and dynamically update thresholds if AI optimization is enabled';

    public function handle()
    {
        $config = HardwareConfig::first();
        
        if (!$config) {
            $this->error('Hardware config not found.');
            return;
        }

        if (!$config->ai_optimization_enabled) {
            $this->info('AI Smart Optimization is currently disabled by the user. Skipping.');
            return;
        }

        try {
            // Call the Python AI Engine
            // Assuming ai_engine is running on port 8000 in Docker network
            $aiUrl = env('AI_ENGINE_URL', 'http://ai_engine:8000');
            $response = Http::timeout(5)->get("{$aiUrl}/predict");
            
            if (!$response->successful()) {
                $this->error('Failed to get prediction from AI Engine: ' . $response->body());
                return;
            }
            
            $data = $response->json();
            $thresholds = $data['suggested_thresholds'] ?? null;
            
            if (!$thresholds) {
                $this->error('Invalid response format from AI Engine.');
                return;
            }

            // Update database
            $config->update([
                'pm25_threshold' => $thresholds['pm25_threshold'],
                'temperature_threshold' => $thresholds['temperature_threshold'],
                'humidity_threshold' => $thresholds['humidity_threshold'],
                'mq135_threshold' => $thresholds['mq135_threshold'],
            ]);

            $this->info("Thresholds updated successfully from AI Engine. Risk Probability: {$data['probability_of_attack']}");

            // Publish to MQTT to immediately sync ESP32
            $server   = env('MQTT_HOST', 'mqtt');
            $port     = env('MQTT_PORT', 1883);
            $clientId = 'laravel_ai_optimizer_' . uniqid();

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
            
            $this->info("ESP32 Edge Device synced with new AI thresholds.");

        } catch (\Exception $e) {
            $this->error('AI Optimization Error: ' . $e->getMessage());
        }
    }
}
