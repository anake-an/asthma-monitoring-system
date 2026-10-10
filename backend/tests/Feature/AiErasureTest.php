<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Patient;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Right to erasure includes the AI: deleting a room, a child or an account deletes the model files
 * trained on their data (ai_engine DELETE /models).
 */
class AiErasureTest extends TestCase
{
    use RefreshDatabase;

    private function deleted(string $query): bool
    {
        return Http::recorded(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), "/models?{$query}"))->isNotEmpty();
    }

    public function test_removing_a_room_or_a_child_deletes_their_models(): void
    {
        $user = User::factory()->create();
        $device = Device::create(['user_id' => $user->id, 'device_token' => 'BED001']);
        $second = Patient::create(['name' => 'Siti']);
        $second->users()->attach($user->id, ['role' => Patient::OWNER]);
        Sanctum::actingAs($user);

        $this->deleteJson("/api/devices/{$device->id}")->assertOk();
        $this->assertTrue($this->deleted("device_id={$device->id}"));

        $this->deleteJson("/api/patients/{$second->id}")->assertOk();
        $this->assertTrue($this->deleted("patient_id={$second->id}"));
    }

    public function test_deleting_an_account_deletes_the_models_of_its_rooms_and_children(): void
    {
        $user = User::factory()->create();
        $patient = $user->defaultPatient();
        $device = Device::create(['user_id' => $user->id, 'device_token' => 'BED001']);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/user')->assertOk();

        $this->assertTrue($this->deleted("device_id={$device->id}&patient_id={$patient->id}"));
    }
}
