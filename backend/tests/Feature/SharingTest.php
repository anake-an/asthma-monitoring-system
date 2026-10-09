<?php

namespace Tests\Feature;

use App\Mail\PatientInviteMail;
use App\Models\AuditLog;
use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\InhalerLog;
use App\Models\Patient;
use App\Models\PatientInvite;
use App\Models\User;
use App\Notifications\CoughAlertNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Multi-patient phase 3: sharing a child (DESIGN_MULTI_PATIENT.md section 3).
 */
class SharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $grandma;
    private Patient $child;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->owner = User::factory()->create(['email' => 'parent@example.test']);
        $this->grandma = User::factory()->create(['email' => 'grandma@example.test']);
        $this->child = $this->owner->defaultPatient();
        $this->child->update(['name' => 'Aiman']);
        $this->bedroom = Device::create(['user_id' => $this->owner->id, 'patient_id' => $this->child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
    }

    /** Owner invites $email as $role; returns the plain token from the email. */
    private function invite(string $email, string $role): string
    {
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/patients/{$this->child->id}/invites", ['email' => $email, 'role' => $role])->assertCreated();

        $token = null;
        Mail::assertSent(PatientInviteMail::class, function (PatientInviteMail $mail) use ($email, &$token) {
            $token = $mail->token;

            return $mail->hasTo(strtolower($email));
        });

        return $token;
    }

    private function share(User $user, string $role): void
    {
        $token = $this->invite($user->email, $role);
        Sanctum::actingAs($user);
        $this->postJson('/api/invites/accept', ['token' => $token])->assertOk();
    }

    public function test_an_invite_is_emailed_and_accepted_by_the_invited_account(): void
    {
        $token = $this->invite('Grandma@Example.test', Patient::CAREGIVER);
        $this->assertSame(1, PatientInvite::count());
        $this->assertNotSame($token, PatientInvite::sole()->token_hash, 'only a hash is stored');

        Sanctum::actingAs($this->grandma);
        $this->getJson("/api/invites/{$token}")->assertOk()
            ->assertJson(['child' => 'Aiman', 'role' => 'caregiver', 'email_matches' => true]);
        $this->postJson('/api/invites/accept', ['token' => $token])->assertOk();

        $this->assertSame(Patient::CAREGIVER, $this->child->roleOf($this->grandma));
        $this->getJson('/api/devices')->assertJsonCount(1, 'devices')->assertJsonPath('devices.0.can_configure', false);
        $this->postJson('/api/invites/accept', ['token' => $token])->assertNotFound(); // used once
    }

    public function test_another_account_cannot_use_the_invite(): void
    {
        $token = $this->invite('grandma@example.test', Patient::OWNER);
        $stranger = User::factory()->create(['email' => 'someone@example.test']);

        Sanctum::actingAs($stranger);
        $this->getJson("/api/invites/{$token}")->assertJson(['email_matches' => false]);
        $this->postJson('/api/invites/accept', ['token' => $token])->assertForbidden();
        $this->postJson('/api/invites/accept', ['token' => 'not-the-token'])->assertNotFound();

        $this->assertNull($this->child->roleOf($stranger));
    }

    public function test_an_invite_expires_after_7_days(): void
    {
        $token = $this->invite('grandma@example.test', Patient::VIEWER);
        $this->travel(PatientInvite::DAYS)->days();
        $this->travel(1)->minutes();

        Sanctum::actingAs($this->grandma);
        $this->postJson('/api/invites/accept', ['token' => $token])->assertNotFound();
    }

    public function test_only_owners_invite_and_manage(): void
    {
        $this->share($this->grandma, Patient::CAREGIVER);

        Sanctum::actingAs($this->grandma);
        $this->postJson("/api/patients/{$this->child->id}/invites", ['email' => 'x@example.test', 'role' => 'viewer'])->assertForbidden();
        $this->patchJson("/api/patients/{$this->child->id}/members/{$this->owner->id}", ['role' => 'viewer'])->assertForbidden();
        $this->getJson("/api/patients/{$this->child->id}/audit")->assertForbidden();
        $this->getJson("/api/patients/{$this->child->id}/members")->assertOk()->assertJsonCount(2, 'members')->assertJsonCount(0, 'invites');
    }

    public function test_a_caregiver_logs_doses_but_cannot_change_limits_or_devices(): void
    {
        $this->share($this->grandma, Patient::CAREGIVER);
        $event = CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 3]);

        Sanctum::actingAs($this->grandma);
        $this->getJson("/api/telemetry?device_id={$this->bedroom->id}")->assertOk();
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $this->child->id])->assertOk();
        $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => false, 'inhaler_used' => false])->assertOk();
        $this->postJson('/api/config', ['device_id' => $this->bedroom->id, 'pm25_threshold' => 10])->assertForbidden();
        $this->patchJson("/api/devices/{$this->bedroom->id}", ['name' => 'Mine'])->assertForbidden();
        $this->deleteJson("/api/devices/{$this->bedroom->id}")->assertForbidden();

        $this->assertSame($this->child->id, InhalerLog::sole()->patient_id);
        $this->assertSame(35.0, HardwareConfig::forDevice($this->bedroom)->pm25_threshold);
    }

    public function test_a_viewer_only_views(): void
    {
        $this->share($this->grandma, Patient::VIEWER);
        $event = CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 3]);

        Sanctum::actingAs($this->grandma);
        $this->getJson("/api/report?patient_id={$this->child->id}")->assertOk()->assertJson(['total_events' => 1]);
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue', 'patient_id' => $this->child->id])->assertForbidden();
        $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => true, 'inhaler_used' => true])->assertForbidden();
    }

    public function test_removing_a_member_takes_effect_at_once(): void
    {
        $this->share($this->grandma, Patient::CAREGIVER);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/patients/{$this->child->id}/members/{$this->grandma->id}")->assertOk();

        Sanctum::actingAs($this->grandma);
        $this->getJson("/api/telemetry?device_id={$this->bedroom->id}")->assertNotFound();
        $this->getJson("/api/report?patient_id={$this->child->id}")->assertNotFound();
        $this->assertFalse(DeviceMessageHandler::alertRecipients($this->bedroom->fresh())->contains('id', $this->grandma->id));
    }

    public function test_members_can_leave_but_a_child_keeps_an_owner(): void
    {
        $this->share($this->grandma, Patient::VIEWER);

        Sanctum::actingAs($this->owner);
        $this->deleteJson("/api/patients/{$this->child->id}/members/{$this->owner->id}")->assertStatus(422);
        $this->patchJson("/api/patients/{$this->child->id}/members/{$this->owner->id}", ['role' => 'viewer'])->assertStatus(422);

        Sanctum::actingAs($this->grandma);
        $this->deleteJson("/api/patients/{$this->child->id}/members/{$this->grandma->id}")->assertOk();
        $this->assertNull($this->child->roleOf($this->grandma));
    }

    public function test_cough_alerts_go_to_owners_and_caregivers_and_viewers_who_opt_in(): void
    {
        Notification::fake();
        $caregiver = User::factory()->create(['email' => 'aunt@example.test']);
        $this->share($caregiver, Patient::CAREGIVER);
        $this->share($this->grandma, Patient::VIEWER);

        $cough = fn () => app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/events', json_encode(['event' => 'cough', 'confidence' => 0.9]));
        $cough();
        $cough(); // the second strong cough in 10 minutes alerts

        Notification::assertSentTo([$this->owner, $caregiver], CoughAlertNotification::class);
        Notification::assertNotSentTo($this->grandma, CoughAlertNotification::class);

        Sanctum::actingAs($this->grandma);
        $this->patchJson("/api/patients/{$this->child->id}/alerts", ['alerts' => true])->assertOk();
        $this->assertTrue(DeviceMessageHandler::alertRecipients($this->bedroom->fresh())->contains('id', $this->grandma->id));
    }

    public function test_deleting_an_account_with_a_shared_child_needs_confirmation(): void
    {
        $this->share($this->grandma, Patient::CAREGIVER);

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/user')->assertStatus(409)->assertJson(['shared_children' => ['Aiman']]);
        $this->assertNotNull($this->owner->fresh());

        $this->deleteJson('/api/user', ['delete_shared_children' => true])->assertOk();
        $this->assertSame(0, Patient::count());
    }

    public function test_a_co_owner_keeps_the_child_and_its_rooms_when_the_other_owner_leaves_for_good(): void
    {
        $this->share($this->grandma, Patient::OWNER);
        HardwareConfig::forDevice($this->bedroom)->applyUserSettings(['pm25_threshold' => 20]);

        Sanctum::actingAs($this->owner);
        $this->deleteJson('/api/user')->assertOk();

        $this->assertSame(Patient::OWNER, $this->child->fresh()->roleOf($this->grandma));
        $this->assertSame($this->grandma->id, $this->bedroom->fresh()->user_id, 'the room stays with the remaining owner');
        $this->assertSame(20.0, HardwareConfig::where('device_id', $this->bedroom->id)->sole()->pm25_cap, 'with its limits');
        $this->assertSame(1, \App\Models\LimitChange::where('device_id', $this->bedroom->id)->count(), 'and their history');
    }

    public function test_sharing_and_changes_are_in_the_audit_log(): void
    {
        $this->share($this->grandma, Patient::CAREGIVER);
        Sanctum::actingAs($this->grandma);
        $this->postJson('/api/inhaler-logs', ['type' => 'controller', 'patient_id' => $this->child->id])->assertOk();
        Sanctum::actingAs($this->owner);
        $this->postJson('/api/config', ['device_id' => $this->bedroom->id, 'pm25_threshold' => 20])->assertOk();
        $this->patchJson("/api/patients/{$this->child->id}/members/{$this->grandma->id}", ['role' => 'viewer'])->assertOk();

        $actions = array_column($this->getJson("/api/patients/{$this->child->id}/audit")->assertOk()->json('entries'), 'action');

        $this->assertSame(['member.role', 'settings.saved', 'dose.logged', 'invite.accepted', 'invite.sent'], $actions);
        $this->assertSame(5, AuditLog::where('patient_id', $this->child->id)->count());
    }
}
