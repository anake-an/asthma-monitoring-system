<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\LimitChange;
use App\Models\User;
use App\Services\CoughSensitivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cough sensitivity per room follows the cough reviews: false alarms raise the threshold, confirmed
 * coughs lower it a little, within limits, once a day, and the device receives the new value.
 */
class CoughSensitivityTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->bedroom = Device::create(['user_id' => $this->owner->id, 'patient_id' => $this->owner->defaultPatient()->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        Sanctum::actingAs($this->owner);
    }

    private function review(?bool $verified, int $minutesAgo = 5, bool $possible = false)
    {
        $event = CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => CoughEvent::SEVERITY_LOGGED, 'is_possible' => $possible, 'recorded_at' => now()->subMinutes($minutesAgo)]);

        return $this->postJson("/api/cough-events/{$event->id}/verify", ['is_verified' => $verified, 'inhaler_used' => false])->assertOk();
    }

    private function threshold(): float
    {
        return HardwareConfig::forDevice($this->bedroom)->fresh()->cough_threshold;
    }

    public function test_the_threshold_starts_at_the_model_default_and_reaches_the_device(): void
    {
        $this->assertSame(0.6, $this->threshold());
        $this->assertSame(0.6, HardwareConfig::forDevice($this->bedroom)->toDevicePayload()['cough_threshold']);
    }

    public function test_three_false_alarms_make_the_room_less_sensitive(): void
    {
        $this->review(false);
        $this->review(false);
        $this->assertSame(0.6, $this->threshold()); // two are not enough

        $this->review(false);

        $this->assertSame(0.65, $this->threshold());
        $sent = json_decode(collect($this->published)->last()[1], true);
        $this->assertSame(0.65, $sent['cough_threshold']);
        $change = LimitChange::sole();
        $this->assertSame(['cough', 60.0, 65.0, 'rule'], [$change->limit_name, $change->old_value, $change->new_value, $change->source]);
        $this->assertStringContainsString('3 of 3 coughs were false alarms', $change->reason);
    }

    public function test_confirmed_coughs_make_the_room_a_little_more_sensitive(): void
    {
        $this->review(true);
        $this->review(true);
        $this->review(true);

        $this->assertSame(0.58, $this->threshold());
    }

    public function test_confirmed_possible_coughs_make_the_room_more_sensitive(): void
    {
        $this->review(true, possible: true);
        $this->assertSame(0.6, $this->threshold()); // one is not enough

        $this->review(true, possible: true);

        $this->assertSame(0.55, $this->threshold());
        $this->assertStringContainsString('2 possible coughs were real', LimitChange::sole()->reason);
    }

    public function test_possible_coughs_that_were_not_coughs_change_nothing(): void
    {
        foreach (range(1, 4) as $i) {
            $this->review(false, possible: true);
        }

        $this->assertSame(0.6, $this->threshold());
    }

    public function test_a_possible_cough_from_the_device_is_kept_for_review_but_never_counted(): void
    {
        $handler = app(\App\Services\DeviceMessageHandler::class);
        foreach (range(1, 4) as $i) { // four would make an alert cluster if they counted
            $handler->handle('respirosync/devices/BED001/events', json_encode(['event' => 'cough', 'level' => 1, 'confidence' => 0.5, 'possible' => true]));
        }

        $events = CoughEvent::all();
        $this->assertCount(4, $events);
        $this->assertTrue($events->every(fn ($e) => $e->is_possible && $e->severity == CoughEvent::SEVERITY_LOGGED));
        $this->getJson("/api/report?patient_id={$this->bedroom->patient_id}")->assertJsonPath('total_events', 0);
        $this->getJson("/api/cough-events?device_id={$this->bedroom->id}&unreviewed=1")->assertJsonPath('total', 0);
        $this->getJson("/api/cough-events?device_id={$this->bedroom->id}")->assertJsonPath('total', 4);

        // Confirmed, it counts.
        $this->postJson("/api/cough-events/{$events->first()->id}/verify", ['is_verified' => true, 'inhaler_used' => false])->assertOk();
        $this->getJson("/api/report?patient_id={$this->bedroom->patient_id}")->assertJsonPath('total_events', 1);
    }

    public function test_a_mix_of_reviews_changes_nothing(): void
    {
        $this->review(true);
        $this->review(true);
        $this->review(false);
        $this->review(false); // 2 of 4 false alarms: under 3, and too many for "only real coughs"

        $this->assertSame(0.6, $this->threshold());
    }

    public function test_one_change_per_day_and_each_step_needs_new_reviews(): void
    {
        foreach (range(1, 3) as $i) {
            $this->review(false);
        }
        $this->assertSame(0.65, $this->threshold());

        foreach (range(1, 3) as $i) {
            $this->review(false);
        }
        $this->assertSame(0.65, $this->threshold()); // within 24 h of the last change

        $this->travel(25)->hours();
        $this->assertNull(CoughSensitivity::adjust($this->bedroom)); // the old reviews do not count again

        foreach (range(1, 3) as $i) {
            $this->review(false);
        }
        $this->assertSame(0.70, $this->threshold());
    }

    public function test_the_threshold_stays_within_its_limits(): void
    {
        HardwareConfig::forDevice($this->bedroom)->update(['cough_threshold' => CoughSensitivity::MAX]);
        foreach (range(1, 3) as $i) {
            $this->review(false);
        }
        $this->assertSame(CoughSensitivity::MAX, $this->threshold());
        $this->assertSame(0, LimitChange::count());
    }

    public function test_nothing_changes_while_automatic_limits_are_off(): void
    {
        HardwareConfig::forDevice($this->bedroom)->update(['ai_optimization_enabled' => false]);
        foreach (range(1, 3) as $i) {
            $this->review(false);
        }

        $this->assertSame(0.6, $this->threshold());
    }
}
