<?php

namespace App\Console\Commands;

use App\Services\DeviceMessageHandler;
use App\Support\Mqtt;
use Illuminate\Console\Command;

/**
 * MQTT Subscriber Background Worker (runs in the mqtt-worker container).
 *
 * Listens on the per-device topics:
 *   respirosync/devices/+/telemetry   environmental readings
 *   respirosync/devices/+/events      cough detections from the Pico (via the ESP32)
 * and hands every message to DeviceMessageHandler.
 */
class MqttSubscribe extends Command
{
    protected $signature = 'mqtt:subscribe';

    protected $description = 'Subscribe to device MQTT topics and store telemetry and cough events';

    public function handle(Mqtt $mqtt, DeviceMessageHandler $handler)
    {
        try {
            $client = $mqtt->client('laravel_worker');
            $this->info('Connected to MQTT broker at ' . config('services.mqtt.host') . ':' . config('services.mqtt.port'));

            $callback = function (string $topic, string $message) use ($handler) {
                try {
                    $handler->handle($topic, $message);
                } catch (\Throwable $e) {
                    // One bad message must not take the worker down.
                    $this->error("Failed to process {$topic}: " . $e->getMessage());
                }
            };

            $client->subscribe(Mqtt::PREFIX . '/+/telemetry', $callback, 1);
            $client->subscribe(Mqtt::PREFIX . '/+/events', $callback, 1);
            $this->info('Subscribed to ' . Mqtt::PREFIX . '/+/{telemetry,events}');

            $client->loop(true);
            $client->disconnect();
        } catch (\Throwable $e) {
            $this->error('MQTT Error: ' . $e->getMessage());

            return self::FAILURE; // container restart policy brings the worker back
        }

        return self::SUCCESS;
    }
}
