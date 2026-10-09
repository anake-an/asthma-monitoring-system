<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AiPredictionTest extends TestCase
{
    use RefreshDatabase;

    public function test_untrained_model_is_reported_as_learning_not_as_an_error(): void
    {
        Http::fake(['*/predict*' => Http::response(['detail' => 'Model not trained yet. Call /train first.'], 400)]);
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/ai/predict')
            ->assertOk()
            ->assertJson([
                'learning' => true,
                'model_stage' => 'Learning mode',
                'probability_of_attack' => null,
                'reason' => 'Model not trained yet. Call /train first.',
            ]);

        Http::assertSent(fn (Request $r) => $r['user_id'] == $user->id);
    }

    public function test_a_real_prediction_is_passed_through(): void
    {
        Http::fake(['*/predict*' => Http::response(['model_stage' => 'Stage 1 (Anomaly Detection)', 'probability_of_attack' => 0.1])]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/ai/predict')
            ->assertOk()
            ->assertJson(['model_stage' => 'Stage 1 (Anomaly Detection)', 'probability_of_attack' => 0.1])
            ->assertJsonMissing(['learning' => true]);
    }

    public function test_an_engine_failure_is_still_an_error(): void
    {
        Http::fake(['*/predict*' => Http::response('boom', 500)]);
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/ai/predict')->assertStatus(500);
    }
}
