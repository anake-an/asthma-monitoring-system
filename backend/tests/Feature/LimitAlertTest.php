<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\LimitAlert;
use App\Models\Patient;
use App\Models\TelemetryLog;
use App\Models\User;
use App\Notifications\LimitAlertNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * A reading above the room's limit for 5 minutes alerts everyone with alerts on (email + push),
 * at most once an hour per room and reading.
 */
class LimitAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->travelTo(now()->startOfHour());
        $this->parent = User::factory()->create();
        $this->parent->defaultPatient()->update(['name' => 'Aiman']);
        $this->bedroom = Device::create(['user_id' => $this->parent->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        HardwareConfig::forDevice($this->bedroom)->applyUserSettings(['pm25_threshold' => 20]);
    }

    /** One reading from the device "now", through the real MQTT handler. */
    private function send(float $pm25, ?float $humidity = 70.0): void
    {
        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/telemetry',
            json_encode(['pm25_level' => $pm25, 'temperature' => 30.0, 'humidity' => $humidity, 'mq135_level' => 500.0]));
    }

    /** Readings every 3 s for $seconds, ending now. */
    private function sendFor(int $seconds, float $pm25): void
    {
        for ($t = 0; $t <= $seconds; $t += 3) {
            $this->send($pm25);
            $this->travel(3)->seconds();
        }
    }

    public function test_five_minutes_over_the_limit_alerts_once(): void
    {
        $this->sendFor(5 * 60 + 3, 27.3);

        Notification::assertSentTo($this->parent, LimitAlertNotification::class, function ($n) {
            $push = $n->toWebPush($this->parent, null)->toArray();

            return $n->alert->reading === 'pm25'
                && str_contains($push['body'], 'Dust (PM2.5) has been above 20 µg/m³ for 5 minutes in Bedroom (Aiman). Now 27.3 µg/m³.');
        });
        Notification::assertSentTimes(LimitAlertNotification::class, 1);
        $this->assertSame(1, LimitAlert::count());
    }

    public function test_four_minutes_is_not_enough(): void
    {
        $this->sendFor(4 * 60, 27.3);

        Notification::assertNothingSent();
    }

    public function test_a_dip_below_the_limit_starts_the_wait_again(): void
    {
        $this->sendFor(3 * 60, 27.3);
        $this->send(15.0); // one reading under the limit
        $this->travel(3)->seconds();
        $this->sendFor(3 * 60, 27.3);

        Notification::assertNothingSent();
    }

    public function test_while_it_stays_high_a_reminder_comes_at_most_every_hour(): void
    {
        $this->sendFor(6 * 60, 27.3);
        Notification::assertSentTimes(LimitAlertNotification::class, 1);

        $this->travel(50)->minutes(); // still high 50 minutes later: within the hour, no reminder
        $this->sendFor(6 * 60, 27.3);
        Notification::assertSentTimes(LimitAlertNotification::class, 1);

        $this->travel(5)->minutes();
        $this->sendFor(6 * 60, 27.3);
        Notification::assertSentTimes(LimitAlertNotification::class, 2);
    }

    public function test_a_failed_sensor_does_not_count_as_over(): void
    {
        HardwareConfig::forDevice($this->bedroom)->applyUserSettings(['humidity_threshold' => 60]);
        for ($t = 0; $t <= 6 * 60; $t += 3) {
            $this->send(5.0, $t === 60 ? null : 80.0); // one failed DHT22 read in the window
            $this->travel(3)->seconds();
        }

        Notification::assertNothingSent(); // dust, gas and temperature are under their limits
    }

    public function test_same_recipients_as_cough_alerts(): void
    {
        $viewer = User::factory()->create();
        $caregiver = User::factory()->create();
        $child = $this->parent->defaultPatient();
        $child->users()->attach($viewer->id, ['role' => Patient::VIEWER, 'alerts' => false]);
        $child->users()->attach($caregiver->id, ['role' => Patient::CAREGIVER, 'alerts' => true]);

        $this->sendFor(5 * 60 + 3, 27.3);

        Notification::assertSentTo([$this->parent, $caregiver], LimitAlertNotification::class);
        Notification::assertNotSentTo($viewer, LimitAlertNotification::class);
    }

    public function test_the_email_says_what_where_and_how_long(): void
    {
        $alert = LimitAlert::create(['device_id' => $this->bedroom->id, 'reading' => 'mq135', 'value' => 1342.6, 'limit_value' => 1000, 'created_at' => now()]);

        $html = (new \App\Mail\LimitAlertMail($alert))->render();

        $this->assertStringContainsString('Gas above the limit', $html);
        $this->assertStringContainsString('Bedroom (Aiman)', $html);
        $this->assertStringContainsString('1343 ppm', $html);
        $this->assertStringContainsString('5 minutes', $html);
    }
}
