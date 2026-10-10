<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\InhalerLog;
use App\Models\User;
use App\Notifications\CoughAlertNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reviewing a cough: inhaler used, real cough, false alarm, or undo; and where false alarms count.
 */
class CoughReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $child = $this->owner->defaultPatient();
        $this->bedroom = Device::create(['user_id' => $this->owner->id, 'patient_id' => $child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        Sanctum::actingAs($this->owner);
    }

    private function cough(int $minutesAgo = 0, int $severity = CoughEvent::SEVERITY_LOGGED): CoughEvent
    {
        return CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => $severity, 'recorded_at' => now()->subMinutes($minutesAgo)]);
    }

    private function review(CoughEvent $event, ?bool $verified, bool $inhaler)
    {
        return $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => $verified, 'inhaler_used' => $inhaler]);
    }

    public function test_an_inhaler_dose_is_logged_at_the_time_of_the_cough(): void
    {
        $event = $this->cough(360); // 6 h ago, reviewed now

        $this->review($event, true, true)->assertOk();

        $dose = InhalerLog::sole();
        $this->assertSame('rescue', $dose->type);
        $this->assertEqualsWithDelta($event->recorded_at->timestamp, $dose->administered_at->timestamp, 1);
    }

    public function test_a_real_cough_without_inhaler_logs_no_dose(): void
    {
        $event = $this->cough();

        $this->review($event, true, false)->assertOk();

        $this->assertTrue($event->fresh()->is_verified);
        $this->assertFalse($event->fresh()->inhaler_used);
        $this->assertSame(0, InhalerLog::count());
    }

    public function test_changing_the_review_removes_the_dose_and_undo_makes_it_unreviewed(): void
    {
        $event = $this->cough();
        $this->review($event, true, true)->assertOk();

        $this->review($event, false, true)->assertOk(); // false alarm: "inhaler" is ignored
        $this->assertFalse($event->fresh()->is_verified);
        $this->assertFalse($event->fresh()->inhaler_used);
        $this->assertSame(0, InhalerLog::count());

        $this->review($event, null, false)->assertOk(); // undo
        $this->assertNull($event->fresh()->is_verified);
    }

    public function test_the_review_field_must_be_sent(): void
    {
        $event = $this->cough();

        $this->postJson("/api/cough-events/{$event->id}/verify", ['inhaler_used' => false])->assertStatus(422);
    }

    public function test_the_report_and_the_banner_leave_false_alarms_out(): void
    {
        $real = $this->cough(10, CoughEvent::SEVERITY_ALERT);
        $falseAlarm = $this->cough(5, CoughEvent::SEVERITY_ALERT);
        $this->review($falseAlarm, false, false)->assertOk();

        $this->getJson('/api/report')
            ->assertJsonPath('total_events', 1)
            ->assertJsonPath('high_severity_events', 1)
            ->assertJsonPath('false_alarms', 1)
            ->assertJsonPath('daily_breakdown.0.count', 1);

        // The newest cough that is not a false alarm (the dashboard's "cough-like sound" banner).
        $this->getJson("/api/cough-events?per_page=1&exclude_false_alarms=1&device_id={$this->bedroom->id}")
            ->assertJsonPath('data.0.id', $real->id);
        // Without the filter, the history still lists every event.
        $this->getJson("/api/cough-events?device_id={$this->bedroom->id}")->assertJsonCount(2, 'data');
    }

    public function test_false_alarms_do_not_make_a_cough_cluster(): void
    {
        Notification::fake();
        foreach ([3, 2] as $minutesAgo) {
            $this->review($this->cough($minutesAgo), false, false)->assertOk();
        }

        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/events', json_encode(['event' => 'cough', 'level' => 1, 'confidence' => 0.2]));

        Notification::assertNothingSent();
        $this->assertSame(CoughEvent::SEVERITY_LOGGED, (int) CoughEvent::latest('id')->first()->severity);
    }
}
