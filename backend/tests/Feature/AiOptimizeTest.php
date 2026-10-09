<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiOptimizeTest extends TestCase
{
    use RefreshDatabase;

    public function test_applies_suggestions_per_user_only_when_enabled(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        Device::create(['user_id' => $alice->id, 'device_token' => 'ALICE1']);
        Device::create(['user_id' => $bob->id, 'device_token' => 'BOB222']);
        HardwareConfig::forUser($alice); // AI optimization on by default
        HardwareConfig::forUser($bob)->update(['ai_optimization_enabled' => false, 'pm25_threshold' => 50]);

        Http::fake(['*/predict*' => Http::response([
            'probability_of_attack' => 0.6,
            'suggested_thresholds' => [
                'pm25_threshold' => 25.0, 'temperature_threshold' => 32.0,
                'humidity_threshold' => 65.0, 'mq135_threshold' => 200.0,
            ],
        ])]);

        $this->artisan('ai:optimize')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $r) => $r['user_id'] == $alice->id);

        $this->assertSame(25.0, HardwareConfig::forUser($alice)->pm25_threshold);
        $this->assertSame(50.0, HardwareConfig::forUser($bob)->pm25_threshold);

        $this->assertCount(1, $this->published);
        $this->assertSame('respirosync/devices/ALICE1/config', $this->published[0][0]);
    }
}
