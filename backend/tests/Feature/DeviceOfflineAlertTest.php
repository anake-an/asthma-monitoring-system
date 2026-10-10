<?php

namespace Tests\Feature;

use App\Mail\DeviceOfflineMail;
use App\Models\Device;
use App\Models\Patient;
use App\Models\User;
use App\Notifications\DeviceBackOnlineNotification;
use App\Notifications\DeviceOfflineNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * "Device offline" alerts (devices:offline-alerts) and the "back online" push.
 */
class DeviceOfflineAlertTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private User $viewer;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->owner = User::factory()->create();
        $child = $this->owner->defaultPatient();
        $child->update(['name' => 'Aiman']);
        $this->viewer = User::factory()->create();
        $child->users()->attach($this->viewer->id, ['role' => Patient::VIEWER, 'alerts' => false]);
        $this->bedroom = Device::create(['user_id' => $this->owner->id, 'patient_id' => $child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
    }

    private function lastSeenMinutesAgo(int $minutes): void
    {
        $this->bedroom->forceFill(['last_seen_at' => now()->subMinutes($minutes)])->save();
    }

    public function test_a_device_silent_for_30_minutes_alerts_its_recipients_once(): void
    {
        $this->lastSeenMinutesAgo(Device::OFFLINE_ALERT_MINUTES + 5);

        $this->artisan('devices:offline-alerts')->assertSuccessful();
        $this->artisan('devices:offline-alerts')->assertSuccessful(); // same outage: nothing new

        Notification::assertSentToTimes($this->owner, DeviceOfflineNotification::class, 1);
        Notification::assertNotSentTo($this->viewer, DeviceOfflineNotification::class); // alerts off
        $this->assertNotNull($this->bedroom->fresh()->offline_alerted_at);
    }

    public function test_no_alert_before_30_minutes_or_for_a_device_that_never_connected(): void
    {
        $this->lastSeenMinutesAgo(Device::OFFLINE_ALERT_MINUTES - 5);
        Device::create(['user_id' => $this->owner->id, 'device_token' => 'NEW001', 'name' => 'Kitchen']); // pending

        $this->artisan('devices:offline-alerts')->assertSuccessful();

        Notification::assertNothingSent();
    }

    public function test_the_next_message_says_it_is_back_and_a_later_outage_alerts_again(): void
    {
        $this->lastSeenMinutesAgo(Device::OFFLINE_ALERT_MINUTES + 5);
        $this->artisan('devices:offline-alerts');

        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/telemetry', json_encode(['pm25_level' => 5]));

        Notification::assertSentToTimes($this->owner, DeviceBackOnlineNotification::class, 1);
        $this->assertNull($this->bedroom->fresh()->offline_alerted_at);

        // A normal message without an earlier alert sends nothing more.
        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/telemetry', json_encode(['pm25_level' => 5]));
        Notification::assertSentToTimes($this->owner, DeviceBackOnlineNotification::class, 1);

        // A new outage alerts again.
        $this->lastSeenMinutesAgo(Device::OFFLINE_ALERT_MINUTES + 1);
        $this->artisan('devices:offline-alerts');
        Notification::assertSentToTimes($this->owner, DeviceOfflineNotification::class, 2);
    }

    public function test_the_email_names_the_room_and_child(): void
    {
        $this->lastSeenMinutesAgo(Device::OFFLINE_ALERT_MINUTES + 5);
        $mail = (new DeviceOfflineMail($this->bedroom->fresh()))->render();

        $this->assertStringContainsString('Bedroom (Aiman)', $mail);
        $this->assertStringContainsString((string) Device::OFFLINE_ALERT_MINUTES . ' minutes', $mail);
    }
}
