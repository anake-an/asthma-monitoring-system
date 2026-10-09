<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\InhalerLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * One account must never see or change another account's data.
 */
class DataScopingTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;
    private User $bob;
    private Device $aliceDevice;
    private Device $bobDevice;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alice = User::factory()->create();
        $this->bob = User::factory()->create();
        $this->aliceDevice = Device::create(['user_id' => $this->alice->id, 'device_token' => 'ALICE1']);
        $this->bobDevice = Device::create(['user_id' => $this->bob->id, 'device_token' => 'BOB222']);
    }

    public function test_config_is_per_user_and_only_pushed_to_own_devices(): void
    {
        Sanctum::actingAs($this->alice);
        $this->postJson('/api/config', ['pm25_threshold' => 20])
            ->assertOk()
            ->assertJson(['pm25_threshold' => 20, 'devices_synced' => 1]);

        $this->assertSame(20.0, HardwareConfig::forUser($this->alice)->pm25_threshold);
        $this->assertSame(35.0, HardwareConfig::forUser($this->bob)->pm25_threshold);

        $this->assertCount(1, $this->published);
        [$topic, $payload, $retain] = $this->published[0];
        $this->assertSame('respirosync/devices/ALICE1/config', $topic);
        $this->assertTrue($retain);
        $this->assertSame(20, json_decode($payload, true)['pm25_threshold']);
    }

    public function test_cannot_verify_another_users_cough_event(): void
    {
        $bobEvent = CoughEvent::create(['device_id' => $this->bobDevice->id, 'severity' => 1]);

        Sanctum::actingAs($this->alice);
        $this->postJson("/api/cough-events/{$bobEvent->id}/verify", ['is_verified' => true, 'inhaler_used' => true])
            ->assertNotFound();

        $this->assertNull($bobEvent->fresh()->is_verified);
        $this->assertSame(0, InhalerLog::count());
    }

    public function test_verifying_own_event_creates_owned_inhaler_log(): void
    {
        $event = CoughEvent::create(['device_id' => $this->aliceDevice->id, 'severity' => 3]);

        Sanctum::actingAs($this->alice);
        $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => true, 'inhaler_used' => true])
            ->assertOk();

        $this->assertSame($this->alice->id, InhalerLog::sole()->user_id);
    }

    public function test_inhaler_status_and_report_only_include_own_data(): void
    {
        InhalerLog::create(['user_id' => $this->bob->id, 'is_manual' => true, 'type' => 'rescue']);
        CoughEvent::create(['device_id' => $this->bobDevice->id, 'severity' => 3]);
        CoughEvent::create(['device_id' => $this->aliceDevice->id, 'severity' => 3]);
        CoughEvent::create(['device_id' => $this->aliceDevice->id, 'severity' => 1]);

        Sanctum::actingAs($this->alice);
        $this->postJson('/api/inhaler-logs', ['type' => 'controller'])->assertOk();

        $this->getJson('/api/inhaler-status')->assertJson(['recent_rescue_count' => 0]);
        $this->getJson('/api/report')->assertJson([
            'total_events' => 2,
            'high_severity_events' => 1,
            'inhaler_doses' => 1,
            'rescue_doses' => 0,
            'controller_doses' => 1,
        ]);
    }

    public function test_deleting_a_device_resets_it_and_clears_its_retained_config(): void
    {
        Sanctum::actingAs($this->alice);
        $this->deleteJson("/api/devices/{$this->aliceDevice->id}")->assertOk();

        $this->assertSame([
            ['respirosync/devices/ALICE1/commands', '{"command":"factory_reset"}', false],
            ['respirosync/devices/ALICE1/config', '', true],
        ], $this->published);

        $this->deleteJson("/api/devices/{$this->bobDevice->id}")->assertNotFound();
    }

    public function test_unauthenticated_requests_get_json_401(): void
    {
        $this->get('/api/config')->assertUnauthorized()->assertJson(['message' => 'Unauthenticated.']);
    }
}
