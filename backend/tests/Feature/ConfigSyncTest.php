<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\User;
use App\Support\Mqtt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConfigSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_device_gets_its_owners_current_limits_as_retained_config(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        Device::create(['user_id' => $alice->id, 'device_token' => 'ALICE1']);
        $bobDevice = Device::create(['user_id' => $bob->id, 'device_token' => 'BOB222']);
        HardwareConfig::forDevice($bobDevice)->update(['mq135_threshold' => 1500]);

        $this->assertSame(2, app(Mqtt::class)->syncAllConfigs());

        $sent = collect($this->published)->keyBy(fn ($p) => $p[0]);
        $this->assertTrue($sent['respirosync/devices/ALICE1/config'][2], 'config must be retained');
        $this->assertSame(1000.0, (float) json_decode($sent['respirosync/devices/ALICE1/config'][1], true)['mq135_threshold']);
        $this->assertSame(1500.0, (float) json_decode($sent['respirosync/devices/BOB222/config'][1], true)['mq135_threshold']);
    }
}
