<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\TelemetryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TelemetryEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_limit_returns_only_the_newest_rows_with_server_time(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_token' => 'ABC123']);
        foreach ([30, 20, 10] as $secondsAgo) {
            TelemetryLog::create(['device_id' => $device->id, 'pm25_level' => $secondsAgo, 'temperature' => 30, 'humidity' => 70,
                'mq135_level' => 450, 'recorded_at' => now()->subSeconds($secondsAgo)]);
        }
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/telemetry?limit=1')->assertOk()->assertJsonCount(1);
        $this->assertSame(10.0, (float) $response->json('0.pm25_level'));
        $this->assertEqualsWithDelta(now()->getTimestampMs(), (int) $response->headers->get('X-Server-Time'), 5000);

        $this->getJson('/api/telemetry')->assertOk()->assertJsonCount(3);
        $this->getJson('/api/telemetry?limit=0')->assertOk()->assertJsonCount(1);
    }

    public function test_new_accounts_get_a_gas_limit_on_the_ppm_scale(): void
    {
        $device = Device::create(['user_id' => User::factory()->create()->id, 'device_token' => 'NEW001']);
        $this->assertSame(1000.0, HardwareConfig::forDevice($device)->mq135_threshold);
    }
}
