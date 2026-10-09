<?php

namespace App\Support;

use App\Models\Device;
use App\Models\HardwareConfig;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

/**
 * Single place for broker credentials and the per-device topic layout.
 *
 *   respirosync/devices/{token}/telemetry   device -> cloud
 *   respirosync/devices/{token}/events      device -> cloud
 *   respirosync/devices/{token}/config      cloud  -> device (retained)
 *   respirosync/devices/{token}/commands    cloud  -> device
 *
 * Resolve it from the container (app(Mqtt::class)) so tests can swap in a fake.
 */
class Mqtt
{
    public const PREFIX = 'respirosync/devices';

    public static function topic(string $token, string $channel): string
    {
        return self::PREFIX . "/{$token}/{$channel}";
    }

    /**
     * Extract [token, channel] from an incoming device topic, or null if it does not match.
     */
    public static function parseTopic(string $topic): ?array
    {
        if (!preg_match('#^respirosync/devices/([A-Z0-9]{6})/(telemetry|events)$#', $topic, $m)) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    public function client(string $clientIdPrefix): MqttClient
    {
        $cfg = config('services.mqtt');
        $client = new MqttClient($cfg['host'], (int) $cfg['port'], $clientIdPrefix . '_' . uniqid());

        $settings = (new ConnectionSettings())
            ->setUsername($cfg['username'])
            ->setPassword($cfg['password'])
            ->setKeepAliveInterval(60)
            ->setConnectTimeout(5)
            ->setUseTls(false); // internal Docker network only; TLS terminates at Cloudflare

        $client->connect($settings, true);

        return $client;
    }

    public function publish(string $topic, string $payload, bool $retain = false): void
    {
        $client = $this->client('laravel_publisher');
        $client->publish($topic, $payload, MqttClient::QOS_AT_LEAST_ONCE, $retain);
        $client->disconnect();
    }

    /**
     * Push the owner's thresholds to one device as a retained message, so the
     * ESP32 receives them immediately and again after every reconnect.
     */
    public function publishConfig(Device $device, HardwareConfig $config): void
    {
        $this->publish(self::topic($device->device_token, 'config'), json_encode($config->toDevicePayload()), true);
    }

    public function clearConfig(Device $device): void
    {
        // An empty retained payload deletes the retained message on the broker.
        $this->publish(self::topic($device->device_token, 'config'), '', true);
    }

    public function sendCommand(Device $device, string $command): void
    {
        $this->publish(self::topic($device->device_token, 'commands'), json_encode(['command' => $command]));
    }
}
