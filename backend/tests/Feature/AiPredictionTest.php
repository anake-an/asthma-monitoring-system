<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The dashboard's AI panel: one prediction per room (DESIGN_MULTI_PATIENT.md section 5).
 */
class AiPredictionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Device $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->device = Device::create(['user_id' => $this->user->id, 'device_token' => 'AI0001']);
        Sanctum::actingAs($this->user);
    }

    public function test_untrained_model_is_reported_as_learning_not_as_an_error(): void
    {
        Http::fake(['*/predict*' => Http::response(['detail' => 'Model not trained yet. Call /train first.'], 400)]);

        $this->getJson('/api/ai/predict')
            ->assertOk()
            ->assertJson([
                'learning' => true,
                'model_stage' => 'Learning mode',
                'probability_of_attack' => null,
                'reason' => 'Model not trained yet. Call /train first.',
                'device_id' => $this->device->id,
            ]);

        Http::assertSent(fn (Request $r) => $r['device_id'] == $this->device->id);
    }

    public function test_a_real_prediction_is_passed_through(): void
    {
        Http::fake(['*/predict*' => Http::response(['model_stage' => 'Stage 1 (Anomaly Detection)', 'probability_of_attack' => 0.1])]);

        $this->getJson("/api/ai/predict?device_id={$this->device->id}")
            ->assertOk()
            ->assertJson(['model_stage' => 'Stage 1 (Anomaly Detection)', 'probability_of_attack' => 0.1])
            ->assertJsonMissing(['learning' => true]);
    }

    public function test_an_engine_failure_is_still_an_error(): void
    {
        Http::fake(['*/predict*' => Http::response('boom', 500)]);

        $this->getJson('/api/ai/predict')->assertStatus(500);
    }

    public function test_no_device_means_learning_and_a_stranger_gets_404(): void
    {
        Http::fake();
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/ai/predict')->assertOk()->assertJson(['learning' => true]);
        $this->getJson("/api/ai/predict?device_id={$this->device->id}")->assertNotFound();
        $this->getJson("/api/ai/train?device_id={$this->device->id}")->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_training_covers_the_room_and_its_child(): void
    {
        Http::fake(['*/train/room*' => Http::response(['message' => 'room ok']), '*/train/risk*' => Http::response(['message' => 'risk ok'])]);

        $this->getJson("/api/ai/train?device_id={$this->device->id}")
            ->assertOk()
            ->assertJson(['room' => ['message' => 'room ok'], 'risk' => ['message' => 'risk ok']]);

        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/train/room') && $r['device_id'] == $this->device->id);
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/train/risk') && $r['patient_id'] == $this->device->patient_id);
    }

    public function test_ai_train_command_trains_every_room_then_every_child(): void
    {
        Http::fake(['*' => Http::response(['message' => 'ok'])]);
        $shared = Device::create(['user_id' => $this->user->id, 'patient_id' => null, 'device_token' => 'AI0002']);

        $this->artisan('ai:train')->assertSuccessful();

        Http::assertSentCount(3); // two room models (incl. the shared room), one child
        Http::assertSent(fn (Request $r) => str_contains($r->url(), '/train/room') && $r['device_id'] == $shared->id);
        Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '/train/risk') && $r['patient_id'] === null);
    }
}
