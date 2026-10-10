<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\InhalerLog;
use App\Models\Patient;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The LCD's "daily dose?" reminder: dose_due in each device's retained config.
 */
class DoseReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Patient $child;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create();
        $this->child = $this->owner->defaultPatient();
        Device::create(['user_id' => $this->owner->id, 'patient_id' => $this->child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
    }

    private function dailyDose(int $hoursAgo): void
    {
        InhalerLog::create(['user_id' => $this->owner->id, 'patient_id' => $this->child->id, 'is_manual' => true, 'type' => 'controller', 'administered_at' => now()->subHours($hoursAgo)]);
    }

    /** dose_due of every config published to BED001 so far. */
    private function sentDoseDue(): array
    {
        return collect($this->published)
            ->filter(fn ($p) => $p[0] === 'respirosync/devices/BED001/config')
            ->map(fn ($p) => json_decode($p[1], true)['dose_due'])
            ->values()->all();
    }

    public function test_an_overdue_daily_dose_is_sent_once_and_cleared_when_logged(): void
    {
        $this->dailyDose(48); // uses a daily inhaler, none for 26 h

        $this->artisan('devices:dose-reminders')->assertSuccessful();
        $this->artisan('devices:dose-reminders')->assertSuccessful(); // unchanged: nothing sent
        $this->assertSame([true], $this->sentDoseDue());

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/inhaler-logs', ['type' => 'controller'])->assertOk();
        $this->assertSame([true, false], $this->sentDoseDue());

        $this->artisan('devices:dose-reminders')->assertSuccessful(); // already sent by the dose
        $this->assertSame([true, false], $this->sentDoseDue());
    }

    public function test_no_reminder_without_a_daily_inhaler_or_when_taken(): void
    {
        $this->artisan('devices:dose-reminders')->assertSuccessful();
        $this->assertSame([false], $this->sentDoseDue());

        $this->dailyDose(3);
        $this->artisan('devices:dose-reminders')->assertSuccessful();
        $this->assertSame([false], $this->sentDoseDue());
    }

    public function test_a_rescue_dose_does_not_clear_the_reminder(): void
    {
        $this->dailyDose(48);
        $this->artisan('devices:dose-reminders');

        Sanctum::actingAs($this->owner);
        $this->postJson('/api/inhaler-logs', ['type' => 'rescue'])->assertOk();
        $this->assertSame([true], $this->sentDoseDue());
    }
}
