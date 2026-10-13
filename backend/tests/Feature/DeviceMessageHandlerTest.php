<?php

namespace Tests\Feature;

use App\Mail\CoughAlertMail;
use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\TelemetryLog;
use App\Models\User;
use App\Notifications\CoughAlertNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DeviceMessageHandlerTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->device = Device::create(['user_id' => $user->id, 'device_token' => 'AAA111', 'status' => 'pending']);
        Notification::fake();
    }

    private function send(string $channel, array $payload, string $token = 'AAA111'): void
    {
        app(DeviceMessageHandler::class)->handle("respirosync/devices/{$token}/{$channel}", json_encode($payload));
    }

    public function test_telemetry_is_stored_and_device_marked_online(): void
    {
        $this->send('telemetry', ['pm25_level' => 12.5, 'temperature' => 29.1, 'humidity' => 71, 'mq135_level' => 410]);

        $log = TelemetryLog::sole();
        $this->assertSame(12.5, $log->pm25_level);
        $this->assertSame(29.1, $log->temperature);
        $this->assertSame('online', $this->device->fresh()->status);
    }

    public function test_failed_dht_read_is_stored_as_null_not_a_fake_value(): void
    {
        $this->send('telemetry', ['pm25_level' => 8.0, 'temperature' => null, 'humidity' => null]);

        $log = TelemetryLog::sole();
        $this->assertNull($log->temperature);
        $this->assertNull($log->humidity);
    }

    public function test_a_time_request_is_answered_with_the_current_time(): void
    {
        $this->send('events', ['event' => 'time_request']);

        [$topic, $payload] = collect($this->published)->sole();
        $this->assertSame('respirosync/devices/AAA111/commands', $topic);
        $answer = json_decode($payload, true);
        $this->assertSame('set_time', $answer['command']);
        $this->assertEqualsWithDelta(time(), $answer['epoch'], 5);
        $this->assertSame(0, CoughEvent::count());
    }

    public function test_telemetry_without_pm25_is_rejected(): void
    {
        $this->send('telemetry', ['temperature' => 30]);

        $this->assertSame(0, TelemetryLog::count());
    }

    public function test_cough_event_does_not_create_a_fake_telemetry_row(): void
    {
        $this->send('events', ['event' => 'cough', 'confidence' => 0.5]);

        $this->assertSame(0, TelemetryLog::count());
        $this->assertSame(1, CoughEvent::count());
    }

    public function test_unknown_token_and_foreign_topics_are_ignored(): void
    {
        $this->send('telemetry', ['pm25_level' => 5], 'ZZZ999');
        app(DeviceMessageHandler::class)->handle('respirosync/telemetry', json_encode(['token' => 'AAA111', 'pm25_level' => 5]));

        $this->assertSame(0, TelemetryLog::count());
    }

    public function test_single_loud_cough_is_logged_but_never_alerts(): void
    {
        $this->send('events', ['event' => 'cough', 'confidence' => 0.99]);

        $this->assertSame(CoughEvent::SEVERITY_LOGGED, (int) CoughEvent::sole()->severity);
        Notification::assertNothingSent();
    }

    public function test_two_strong_detections_alert(): void
    {
        $this->send('events', ['event' => 'cough', 'confidence' => 0.9]);
        $this->send('events', ['event' => 'cough', 'confidence' => 0.85]);

        Notification::assertSentTimes(CoughAlertNotification::class, 1);
    }

    public function test_three_weak_coughs_alert_and_cooldown_suppresses_repeats(): void
    {
        foreach (range(1, 5) as $_) {
            $this->send('events', ['event' => 'cough', 'confidence' => 0.2]);
        }

        // 3rd, 4th and 5th meet the rule, but only one notification inside the 10-minute window
        $this->assertSame(3, CoughEvent::where('severity', CoughEvent::SEVERITY_ALERT)->count());
        Notification::assertSentTimes(CoughAlertNotification::class, 1);
    }

    public function test_missing_confidence_is_stored_as_null(): void
    {
        $this->send('events', ['event' => 'cough']);

        $this->assertNull(CoughEvent::sole()->confidence);
    }

    public function test_alert_email_never_invents_a_confidence_value(): void
    {
        $this->send('events', ['event' => 'cough']);
        $html = (new CoughAlertMail(CoughEvent::sole(), 3))->render();

        $this->assertStringNotContainsString('98.5', $html);
        $this->assertStringContainsString('Not reported by device', $html);
        $this->assertStringContainsString('3 in the last 10 minutes', $html);
    }

    public function test_coughs_held_while_offline_keep_their_time_and_never_alert(): void
    {
        $at = now()->subMinutes(40)->startOfSecond();
        foreach (range(0, 3) as $i) { // a cluster that would alert if it were live
            $this->send('events', ['event' => 'cough', 'level' => 1, 'confidence' => 0.9, 'ts' => $at->copy()->addSeconds($i * 20)->timestamp]);
        }

        $events = CoughEvent::orderBy('recorded_at')->get();
        $this->assertCount(4, $events);
        $this->assertTrue($events->first()->recorded_at->equalTo($at));
        $this->assertTrue($events->every(fn ($e) => $e->severity == CoughEvent::SEVERITY_LOGGED));
        Notification::assertNothingSent();
    }

    public function test_a_live_cough_uses_the_arrival_time_even_if_the_device_clock_is_off(): void
    {
        $this->send('events', ['event' => 'cough', 'level' => 1, 'confidence' => 0.7, 'ts' => now()->addMinutes(3)->timestamp]);
        $this->send('events', ['event' => 'cough', 'level' => 1, 'confidence' => 0.7, 'ts' => now()->subDays(30)->timestamp]);

        foreach (CoughEvent::all() as $event) {
            $this->assertTrue($event->recorded_at->gt(now()->subMinute()));
        }
    }

    public function test_an_edge_ai_module_that_stopped_answering_shows_as_not_connected(): void
    {
        $this->send('events', ['event' => 'hello', 'firmware' => '3.5.0', 'build' => '261014', 'edge_ai' => '3.2.0', 'edge_ai_build' => '261014']);
        $this->assertSame('3.2.0', $this->device->fresh()->edge_ai_version);

        $this->send('events', ['event' => 'hello', 'firmware' => '3.5.0', 'build' => '261014', 'edge_ai_lost' => true]);

        $this->assertNull($this->device->fresh()->edge_ai_version);
        $this->assertNull($this->device->fresh()->edge_ai_build);
    }
}
