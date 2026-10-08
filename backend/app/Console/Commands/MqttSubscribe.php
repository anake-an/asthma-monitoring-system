<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpMqtt\Client\MqttClient;
use PhpMqtt\Client\ConnectionSettings;
use App\Models\TelemetryLog;
use App\Models\CoughEvent;
use App\Models\Device;

/**
 * MQTT Subscriber Background Worker
 * 
 * This command is designed to be run as a daemon (e.g., via Supervisor or a Docker long-running process).
 * It maintains a persistent TCP connection to the Mosquitto MQTT broker and listens for:
 * 1. `asthma/telemetry`: Environmental data (PM2.5, Temp, Humidity) from the ESP32.
 * 2. `asthma/cough`: Cough severity events detected by the AI Engine.
 * 
 * When a message is received, it decodes the JSON payload and inserts it directly into the MySQL database.
 */
class MqttSubscribe extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'mqtt:subscribe';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Subscribe to MQTT topics and save telemetry to database';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $server   = env('MQTT_HOST', 'mqtt');
        $port     = env('MQTT_PORT', 1883);
        $clientId = 'laravel_worker_' . uniqid();

        try {
            $mqtt = new MqttClient($server, $port, $clientId);

            $settings = (new ConnectionSettings())
                ->setKeepAliveInterval(60)
                ->setUseTls(false)
                ->setTlsSelfSignedAllowed(true);

            $mqtt->connect($settings, true);

            $this->info("Connected to MQTT Broker at {$server}:{$port}");

            $mqtt->subscribe('respirosync/telemetry', function (string $topic, string $message) {
                $this->info("Received telemetry: $message");
                $data = json_decode($message, true);

                if ($data && isset($data['token'])) {
                    $device = Device::where('device_token', $data['token'])->first();
                    
                    if ($device) {
                        // Mark device as online
                        if ($device->status !== 'online') {
                            $device->update(['status' => 'online']);
                        }

                        // Save Telemetry
                        TelemetryLog::create([
                            'device_id' => $device->id,
                            'pm25_level' => $data['pm25_level'] ?? 15.0,
                            'temperature' => $data['temperature'] ?? 30.0,
                            'humidity' => $data['humidity'] ?? 60.0,
                            'mq135_level' => $data['mq135_level'] ?? null,
                        ]);

                        // Save Cough Event if present
                        if (isset($data['event']) && $data['event'] === 'cough') {
                            $confidence = isset($data['confidence']) ? floatval($data['confidence']) : null;
                            $isSevere = false;
                            
                            if ($confidence !== null) {
                                // If hardware Pico provides AI confidence, filter it (> 80% confident it's an asthma attack)
                                if ($confidence >= 0.80) {
                                    $isSevere = true;
                                }
                            } else {
                                // Fallback for simple sound sensors: Wait for 3 coughs in 10 minutes to trigger alert
                                $recentCoughs = CoughEvent::where('device_id', $device->id)
                                    ->where('recorded_at', '>=', now()->subMinutes(10))
                                    ->count();
                                    
                                if ($recentCoughs >= 3) {
                                    $isSevere = true;
                                }
                            }

                            $cough = CoughEvent::create([
                                'device_id' => $device->id,
                                'severity' => $isSevere ? 3 : 1, // 1 = Normal/Minor, 3 = High Severity
                                'confidence' => $confidence ?? 0.0,
                            ]);
                            
                            // Send Emergency Notification ONLY if verified as a severe asthma attack
                            if ($isSevere && $device->user) {
                                $device->user->notify(new \App\Notifications\CoughAlertNotification($cough));
                            }
                        }
                    } else {
                        $this->warn("Ignored MQTT payload: Invalid token ({$data['token']})");
                    }
                }
            }, 0);

            $this->info("Subscribed to respirosync/telemetry topic.");

            $mqtt->loop(true);
            $mqtt->disconnect();
        } catch (\Exception $e) {
            $this->error("MQTT Error: " . $e->getMessage());
        }
    }
}
