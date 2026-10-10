<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\User;
use App\Notifications\CoughAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PushSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_browser_subscribes_and_unsubscribes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $endpoint = 'https://fcm.googleapis.com/fcm/send/abc123';

        $this->postJson('/api/push-subscribe', ['endpoint' => $endpoint, 'keys' => ['auth' => 'a', 'p256dh' => 'p']])->assertOk();
        $this->assertSame(1, $user->pushSubscriptions()->count());

        $this->deleteJson('/api/push-subscribe', ['endpoint' => $endpoint])->assertOk();
        $this->assertSame(0, $user->pushSubscriptions()->count());
    }

    public function test_the_push_names_the_room_and_child_and_opens_that_room(): void
    {
        $user = User::factory()->create();
        $user->defaultPatient()->update(['name' => 'Aiman']);
        $device = Device::create(['user_id' => $user->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        $event = CoughEvent::create(['device_id' => $device->id, 'severity' => 3]);

        $message = (new CoughAlertNotification($event, 3))->toWebPush($user, null)->toArray();

        $this->assertStringContainsString('3 coughs detected in Bedroom (Aiman)', $message['body']);
        $this->assertSame("/?device={$device->id}", $message['data']['url']);
        $this->assertSame("cough-{$device->id}", $message['tag']);

        $email = (new \App\Mail\CoughAlertMail($event, 3))->render();
        $this->assertStringContainsString('Bedroom (Aiman)', $email);
        $this->assertStringNotContainsString('Medical Dashboard', $email);
    }
}
