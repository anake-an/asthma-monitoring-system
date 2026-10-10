<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\InhalerLog;
use App\Models\LimitChange;
use App\Models\Patient;
use App\Models\TelemetryLog;
use App\Models\User;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-patient phase 2: patients, one config per device, per-room data, last seen / offline.
 */
class PatientsAndDevicesTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;
    private Patient $aiman;
    private Patient $siti;
    private Device $bedroom;
    private Device $nursery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = User::factory()->create();
        $this->aiman = $this->parent->defaultPatient();
        $this->aiman->update(['name' => 'Aiman']);
        $this->siti = Patient::create(['name' => 'Siti']);
        $this->siti->users()->attach($this->parent->id, ['role' => Patient::OWNER]);
        $this->bedroom = Device::create(['user_id' => $this->parent->id, 'patient_id' => $this->aiman->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        $this->nursery = Device::create(['user_id' => $this->parent->id, 'patient_id' => $this->siti->id, 'device_token' => 'NUR001', 'name' => 'Nursery']);
        Sanctum::actingAs($this->parent);
    }

    private function reading(Device $device, float $pm25, int $secondsAgo): void
    {
        TelemetryLog::create(['device_id' => $device->id, 'pm25_level' => $pm25, 'temperature' => 30, 'humidity' => 70,
            'mq135_level' => 450, 'recorded_at' => now()->subSeconds($secondsAgo)]);
    }

    public function test_a_first_child_is_created_by_pairing_not_by_registering(): void
    {
        $this->postJson('/api/register', ['name' => 'New', 'email' => 'new@example.test', 'password' => 'password123', 'password_confirmation' => 'password123'])
            ->assertCreated();
        $user = User::where('email', 'new@example.test')->sole();
        $this->assertSame(0, $user->patients()->count(), 'an invitee must not get an empty child of their own');

        Sanctum::actingAs($user);
        $this->getJson('/api/patients')->assertOk()->assertJsonCount(0, 'patients');
        $this->getJson('/api/report')->assertNotFound(); // no child to report on, nothing created
        $this->assertSame(0, $user->patients()->count());

        $this->postJson('/api/devices/generate-token')->assertOk();
        $this->assertSame([Patient::DEFAULT_NAME], $user->patients()->pluck('name')->all());
        $this->assertSame(Patient::OWNER, $user->patients()->first()->pivot->role);
    }

    public function test_the_cleanup_removes_only_empty_auto_children_of_people_with_another_child(): void
    {
        $make = function (User $u, string $name = 'My child'): Patient {
            $p = Patient::create(['name' => $name]);
            $p->users()->attach($u->id, ['role' => Patient::OWNER]);

            return $p;
        };
        $invitee = User::factory()->create();
        $leftover = $make($invitee);                               // empty, and the invitee sees Aiman
        $this->aiman->users()->attach($invitee->id, ['role' => Patient::VIEWER]);
        $onlyChild = $make(User::factory()->create());             // someone's only child: keep
        $withRoom = $make($invitee);                               // has a room: keep
        Device::create(['user_id' => $invitee->id, 'patient_id' => $withRoom->id, 'device_token' => 'INV001']);
        $renamed = $make($invitee, 'Adam');                        // renamed: keep

        $migration = require database_path('migrations/2026_10_10_000009_remove_unused_auto_children.php');
        $this->assertSame(1, $migration->cleanup());

        $this->assertNull(Patient::find($leftover->id));
        $this->assertNotNull(Patient::find($onlyChild->id));
        $this->assertNotNull(Patient::find($withRoom->id));
        $this->assertNotNull(Patient::find($renamed->id));
        $this->assertNotNull(Patient::find($this->aiman->id));
    }

    public function test_someone_a_child_is_shared_with_sees_it_by_default_and_cannot_pair_for_it(): void
    {
        $viewer = User::factory()->create();
        $this->aiman->users()->attach($viewer->id, ['role' => Patient::VIEWER]);
        Sanctum::actingAs($viewer);

        $this->getJson('/api/report')->assertOk()->assertJsonPath('patient.name', 'Aiman');
        $this->postJson('/api/devices/generate-token', ['patient_id' => $this->aiman->id])->assertForbidden();
        $this->assertSame(['Aiman'], $viewer->patients()->pluck('name')->all());
    }

    public function test_telemetry_is_per_device_and_defaults_to_the_most_recently_seen(): void
    {
        $this->reading($this->bedroom, 11, 5);
        $this->reading($this->nursery, 22, 4);
        $this->nursery->update(['last_seen_at' => now()]);
        $this->bedroom->update(['last_seen_at' => now()->subMinute()]);

        $pm25 = fn (string $query) => (float) $this->getJson("/api/telemetry?limit=1{$query}")->assertOk()->json('0.pm25_level');
        $this->assertSame(11.0, $pm25("&device_id={$this->bedroom->id}"));
        $this->assertSame(22.0, $pm25("&device_id={$this->nursery->id}"));
        $this->assertSame(22.0, $pm25(''), 'no id: the most recently seen device');
    }

    public function test_a_stranger_gets_404_for_every_device_and_patient_endpoint(): void
    {
        $stranger = User::factory()->create();
        Sanctum::actingAs($stranger);
        $d = $this->bedroom->id;
        $p = $this->aiman->id;

        $this->getJson("/api/telemetry?device_id={$d}")->assertNotFound();
        $this->getJson("/api/cough-events?device_id={$d}")->assertNotFound();
        $this->getJson("/api/config?device_id={$d}")->assertNotFound();
        $this->postJson('/api/config', ['device_id' => $d, 'pm25_threshold' => 20])->assertNotFound();
        $this->patchJson("/api/devices/{$d}", ['name' => 'Mine'])->assertNotFound();
        $this->deleteJson("/api/devices/{$d}")->assertNotFound();
        $this->getJson("/api/inhaler-status?patient_id={$p}")->assertNotFound();
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $p])->assertNotFound();
        $this->getJson("/api/report?patient_id={$p}")->assertNotFound();
        $this->patchJson("/api/patients/{$p}", ['name' => 'X'])->assertNotFound();
        $this->deleteJson("/api/patients/{$p}")->assertNotFound();
        $this->postJson('/api/devices/generate-token', ['patient_id' => $p])->assertNotFound();

        $this->getJson('/api/devices')->assertOk()->assertJsonCount(0, 'devices');
        $this->assertSame('Bedroom', $this->bedroom->fresh()->name);
        $this->assertSame(35.0, HardwareConfig::forDevice($this->bedroom)->pm25_threshold);
    }

    public function test_limits_are_per_device_and_only_sent_to_that_device(): void
    {
        $this->postJson('/api/config', ['device_id' => $this->nursery->id, 'pm25_threshold' => 20])
            ->assertOk()->assertJson(['pm25_threshold' => 20, 'device_id' => $this->nursery->id, 'devices_synced' => 1]);

        $this->assertSame(20.0, HardwareConfig::forDevice($this->nursery)->pm25_threshold);
        $this->assertSame(35.0, HardwareConfig::forDevice($this->bedroom)->pm25_threshold);
        $this->assertSame(['respirosync/devices/NUR001/config'], array_column($this->published, 0));
        $this->assertSame($this->nursery->id, LimitChange::sole()->device_id);

        $this->getJson("/api/config?device_id={$this->bedroom->id}")->assertJson(['pm25_threshold' => 35]);
    }

    public function test_a_new_room_of_a_child_starts_from_that_childs_limits(): void
    {
        HardwareConfig::forDevice($this->bedroom)->applyUserSettings(['pm25_threshold' => 20, 'humidity_locked' => true]);

        $this->postJson('/api/devices/generate-token', ['patient_id' => $this->aiman->id])->assertOk();
        $new = Device::where('patient_id', $this->aiman->id)->latest('id')->first();

        $c = HardwareConfig::forDevice($new);
        $this->assertSame(20.0, $c->pm25_threshold);
        $this->assertSame(20.0, $c->pm25_cap);
        $this->assertTrue($c->humidity_locked);
        $this->assertSame(35.0, HardwareConfig::forDevice($this->nursery)->pm25_threshold, 'another child keeps its own');
    }

    public function test_devices_can_be_renamed_and_moved_but_only_to_own_patients(): void
    {
        $this->patchJson("/api/devices/{$this->bedroom->id}", ['name' => 'Aiman room', 'patient_id' => $this->siti->id])
            ->assertOk()->assertJsonPath('patient.name', 'Siti');

        $this->patchJson("/api/devices/{$this->bedroom->id}", ['patient_id' => null])->assertOk(); // shared room
        $this->assertNull($this->bedroom->fresh()->patient_id);

        $foreign = User::factory()->create()->defaultPatient();
        $this->patchJson("/api/devices/{$this->bedroom->id}", ['patient_id' => $foreign->id])->assertNotFound();
    }

    public function test_device_list_shows_child_status_and_rights(): void
    {
        $this->bedroom->update(['last_seen_at' => now()]);
        $this->nursery->update(['last_seen_at' => now()->subMinutes(5)]);

        $devices = collect($this->getJson('/api/devices')->assertOk()->json('devices'))->keyBy('name');

        $this->assertSame('online', $devices['Bedroom']['status']);
        $this->assertSame('offline', $devices['Nursery']['status']);
        $this->assertSame('Aiman', $devices['Bedroom']['patient']['name']);
        $this->assertTrue($devices['Bedroom']['can_configure']);
    }

    public function test_every_mqtt_message_updates_last_seen(): void
    {
        $this->travelTo(now()->startOfMinute());
        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/telemetry', json_encode(['pm25_level' => 5.0]));

        $this->assertTrue($this->bedroom->fresh()->last_seen_at->eq(now()));
        $this->assertSame('online', $this->bedroom->fresh()->status);
        $this->travel(Device::OFFLINE_AFTER_SECONDS + 1)->seconds();
        $this->assertSame('offline', $this->bedroom->fresh()->status);
    }

    public function test_doses_and_reports_are_per_patient(): void
    {
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $this->siti->id])->assertOk();
        CoughEvent::create(['device_id' => $this->nursery->id, 'severity' => 3]);
        CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 1]);

        $this->getJson("/api/inhaler-status?patient_id={$this->siti->id}")->assertJson(['recent_rescue_count' => 1]);
        $this->getJson("/api/inhaler-status?patient_id={$this->aiman->id}")->assertJson(['recent_rescue_count' => 0]);
        $this->getJson("/api/report?patient_id={$this->siti->id}")
            ->assertJson(['patient' => ['name' => 'Siti'], 'total_events' => 1, 'high_severity_events' => 1, 'rescue_doses' => 1]);
        $this->getJson('/api/report')->assertJson(['patient' => ['name' => 'Aiman'], 'total_events' => 1, 'rescue_doses' => 0]);
    }

    public function test_cough_events_carry_their_room_and_can_be_filtered(): void
    {
        CoughEvent::create(['device_id' => $this->nursery->id, 'severity' => 1]);
        CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 1]);

        $this->getJson('/api/cough-events')->assertJsonCount(2, 'data');
        $this->getJson("/api/cough-events?device_id={$this->nursery->id}")
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.device.name', 'Nursery');
    }

    public function test_a_shared_room_dose_from_a_cough_is_not_attributed_to_a_child(): void
    {
        $this->bedroom->update(['patient_id' => null]);
        $event = CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 3]);

        $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => true, 'inhaler_used' => true])->assertOk();

        $this->assertNull(InhalerLog::sole()->patient_id);
    }

    public function test_patients_can_be_added_renamed_and_deleted_with_their_doses(): void
    {
        $id = $this->postJson('/api/patients', ['name' => 'Adam', 'birth_year' => 2019])->assertCreated()->json('id');
        $this->patchJson("/api/patients/{$id}", ['name' => 'Adam Jr'])->assertOk()->assertJson(['name' => 'Adam Jr']);
        $this->assertSame(['Aiman', 'Siti', 'Adam Jr'], array_column($this->getJson('/api/patients')->json('patients'), 'name'));

        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $this->siti->id])->assertOk();
        $this->deleteJson("/api/patients/{$this->siti->id}")->assertOk();
        $this->assertSame(0, InhalerLog::count(), 'the child\'s dose history goes with them');
        $this->assertNull($this->nursery->fresh()->patient_id, 'their room stays, as a shared room');

        $this->deleteJson("/api/patients/{$id}")->assertOk();
        $this->deleteJson("/api/patients/{$this->aiman->id}")->assertStatus(422); // the last one stays
    }

    public function test_ai_optimize_applies_one_suggestion_per_room_with_that_rooms_caps(): void
    {
        HardwareConfig::forDevice($this->bedroom)->applyUserSettings(['pm25_threshold' => 20]);
        HardwareConfig::forDevice($this->nursery);
        Http::fake(['*/predict*' => Http::response(['probability_of_attack' => 0.5, 'ready_to_adjust' => true,
            'suggested_thresholds' => ['pm25_threshold' => 25.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 75.0, 'mq135_threshold' => 1000.0]])]);
        $this->published = [];

        $this->artisan('ai:optimize')->assertSuccessful();

        Http::assertSentCount(2); // one prediction per room (room model + its child's risk model)
        $this->assertSame(20.0, HardwareConfig::forDevice($this->bedroom)->pm25_threshold, 'cap 20 wins');
        $this->assertSame(31.5, HardwareConfig::forDevice($this->nursery)->pm25_threshold, '35 - 10 %');
        $this->assertSame(['respirosync/devices/NUR001/config'], array_column($this->published, 0));
    }

    public function test_the_missed_dose_rule_follows_the_rooms_child(): void
    {
        HardwareConfig::forDevice($this->bedroom);
        HardwareConfig::forDevice($this->nursery);
        InhalerLog::create(['user_id' => $this->parent->id, 'patient_id' => $this->siti->id, 'type' => 'controller', 'is_manual' => true, 'administered_at' => now()->subHours(30)]);
        Http::fake(['*/predict*' => Http::response(['detail' => 'learning'], 400)]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(29.8, HardwareConfig::forDevice($this->nursery)->fresh()->pm25_threshold, 'Siti missed her dose');
        $this->assertSame(35.0, HardwareConfig::forDevice($this->bedroom)->fresh()->pm25_threshold, 'Aiman did not');
    }

    public function test_deleting_an_account_erases_its_patients_and_their_doses(): void
    {
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $this->aiman->id])->assertOk();

        $this->deleteJson('/api/user')->assertOk();

        $this->assertSame(0, Patient::count());
        $this->assertSame(0, InhalerLog::count());
        $this->assertSame(0, Device::count());
    }

    public function test_the_migration_backfill_links_existing_data(): void
    {
        // Data as it was before patients: an account with two devices, one config, a dose, a limit change.
        $old = User::factory()->create();
        $d1 = DB::table('devices')->insertGetId(['user_id' => $old->id, 'device_token' => 'OLD001', 'name' => 'A', 'status' => 'online', 'created_at' => now(), 'updated_at' => now()]);
        $d2 = DB::table('devices')->insertGetId(['user_id' => $old->id, 'device_token' => 'OLD002', 'name' => 'B', 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('hardware_configs')->insert(['user_id' => $old->id] + array_map(fn ($v) => is_bool($v) ? (int) $v : $v, ['pm25_threshold' => 24.0, 'pm25_cap' => 24.0] + HardwareConfig::DEFAULTS));
        DB::table('inhaler_logs')->insert(['user_id' => $old->id, 'type' => 'rescue', 'is_manual' => 1, 'administered_at' => now()]);
        DB::table('limit_changes')->insert(['user_id' => $old->id, 'limit_name' => 'pm25', 'old_value' => 35, 'new_value' => 24, 'source' => 'user', 'created_at' => now()]);

        $migration = require database_path('migrations/2026_10_10_000005_add_patients_and_per_device_configs.php');
        $migration->backfill();
        $migration->backfill(); // idempotent

        $patient = $old->patients()->sole();
        $this->assertSame(Patient::DEFAULT_NAME, $patient->name);
        $this->assertSame(2, DB::table('devices')->where('patient_id', $patient->id)->count());
        $this->assertSame($patient->id, (int) DB::table('inhaler_logs')->where('user_id', $old->id)->value('patient_id'));
        foreach ([$d1, $d2] as $deviceId) {
            $this->assertSame(24.0, (float) DB::table('hardware_configs')->where('device_id', $deviceId)->value('pm25_threshold'), 'every device keeps the account limits');
        }
        $this->assertSame(0, DB::table('hardware_configs')->whereNull('device_id')->count());
        $this->assertNull(DB::table('limit_changes')->where('user_id', $old->id)->value('device_id'), 'two devices: the old change cannot be placed');
    }
}
