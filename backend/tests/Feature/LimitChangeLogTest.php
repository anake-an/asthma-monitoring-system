<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\LimitChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Every change of an effective alert limit is logged with who made it and why,
 * and shown in the weekly report (Activity Log).
 */
class LimitChangeLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Device::create(['user_id' => $this->user->id, 'device_token' => 'LOG001']);
    }

    private function aiSuggests(array $limits, bool $ready = true, string $stage = 'Stage 1 (Anomaly Detection)'): void
    {
        Http::fake(['*/predict*' => Http::response([
            'probability_of_attack' => 0.5, 'model_stage' => $stage,
            'ready_to_adjust' => $ready, 'suggested_thresholds' => $limits,
        ])]);
    }

    public function test_an_ai_change_is_logged_with_its_reason(): void
    {
        HardwareConfig::forUser($this->user); // humidity 75
        $this->aiSuggests(['pm25_threshold' => 35.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 70.0, 'mq135_threshold' => 1000.0]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(1, LimitChange::count(), 'only the limit that moved is logged');
        $change = LimitChange::first();
        $this->assertSame('humidity', $change->limit_name);
        $this->assertSame(75.0, $change->old_value);
        $this->assertSame(70.0, $change->new_value);
        $this->assertSame('ai', $change->source);
        $this->assertSame('Room unusual (Stage 1)', $change->reason);
    }

    public function test_unchanged_limits_are_not_logged(): void
    {
        HardwareConfig::forUser($this->user);
        $this->aiSuggests(['pm25_threshold' => 35.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 75.0, 'mq135_threshold' => 1000.0]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(0, LimitChange::count());
        $this->assertCount(0, $this->published);
    }

    public function test_a_user_change_is_logged(): void
    {
        HardwareConfig::forUser($this->user);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/config', ['pm25_threshold' => 20, 'humidity_locked' => true])->assertOk();

        $this->assertSame(1, LimitChange::count(), 'a lock alone is not a limit change');
        $this->assertDatabaseHas('limit_changes', [
            'user_id' => $this->user->id, 'limit_name' => 'pm25', 'old_value' => 35, 'new_value' => 20, 'source' => 'user',
        ]);
    }

    public function test_limits_return_to_the_users_values_while_the_ai_is_not_ready(): void
    {
        // e.g. lowered by the AI before the 24 h rule existed, or before an AI reset
        HardwareConfig::forUser($this->user)->update(['pm25_threshold' => 25.0, 'humidity_threshold' => 73.2]);
        $this->aiSuggests(['pm25_threshold' => 20.0, 'temperature_threshold' => 30.0, 'humidity_threshold' => 60.0, 'mq135_threshold' => 800.0], ready: false);

        $this->artisan('ai:optimize')->assertSuccessful();

        $c = HardwareConfig::forUser($this->user)->fresh();
        $this->assertSame(35.0, $c->pm25_threshold);
        $this->assertSame(75.0, $c->humidity_threshold);
        $this->assertSame(2, LimitChange::where('reason', 'Back to your limit: less than 24 h of readings')->count());
        $this->assertCount(1, $this->published);
    }

    public function test_limits_return_to_the_users_values_while_the_ai_is_learning(): void
    {
        HardwareConfig::forUser($this->user)->update(['mq135_threshold' => 900.0]);
        Http::fake(['*/predict*' => Http::response(['detail' => 'No model trained yet'], 404)]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(1000.0, HardwareConfig::forUser($this->user)->fresh()->mq135_threshold);
        $this->assertSame('Back to your limit: the AI is still learning', LimitChange::first()->reason);
    }

    public function test_an_engine_error_leaves_the_limits_alone(): void
    {
        HardwareConfig::forUser($this->user)->update(['pm25_threshold' => 25.0]);
        Http::fake(['*/predict*' => Http::response('Internal Server Error', 500)]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(25.0, HardwareConfig::forUser($this->user)->fresh()->pm25_threshold);
        $this->assertSame(0, LimitChange::count());
    }

    public function test_the_weekly_report_lists_this_weeks_changes_newest_first(): void
    {
        $other = User::factory()->create();
        $row = fn (User $u, string $name, string $when) => LimitChange::create([
            'user_id' => $u->id, 'limit_name' => $name, 'old_value' => 35, 'new_value' => 31.5,
            'source' => 'ai', 'reason' => 'Room unusual (Stage 1)', 'created_at' => now()->sub($when),
        ]);
        $row($this->user, 'pm25', '2 days');
        $row($this->user, 'humidity', '1 hour');
        $row($this->user, 'mq135', '10 days'); // older than the report period
        $row($other, 'temperature', '1 hour'); // another account

        Sanctum::actingAs($this->user);
        $changes = $this->getJson('/api/report')->assertOk()->json('limit_changes');

        $this->assertSame(['humidity', 'pm25'], array_column($changes, 'limit_name'));
        $this->assertSame('ai', $changes[0]['source']);
        $this->assertSame('Room unusual (Stage 1)', $changes[0]['reason']);
    }
}
