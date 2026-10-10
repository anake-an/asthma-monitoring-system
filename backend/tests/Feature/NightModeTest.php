<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Night mode (LCD backlight off 21:00-07:00): on by default, switched per room in Smart Alerts,
 * and sent to the device in its config.
 */
class NightModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_night_mode_is_on_by_default_and_can_be_switched_off(): void
    {
        $owner = User::factory()->create();
        $device = Device::create(['user_id' => $owner->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        Sanctum::actingAs($owner);

        $this->getJson("/api/config?device_id={$device->id}")->assertJsonPath('night_mode', true);
        $this->assertTrue(HardwareConfig::forDevice($device)->toDevicePayload()['night_mode']);

        $this->postJson('/api/config', ['device_id' => $device->id, 'night_mode' => false])->assertOk();

        $this->assertFalse(HardwareConfig::forDevice($device)->fresh()->night_mode);
        $sent = json_decode(collect($this->published)->last()[1], true);
        $this->assertFalse($sent['night_mode']);
    }
}
