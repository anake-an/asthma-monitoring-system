<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The user's value is a cap: the AI may tighten a limit below it, never raise it above,
 * and never touches a locked limit.
 */
class LimitCapsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Device::create(['user_id' => $this->user->id, 'device_token' => 'CAPS01']);
    }

    private function aiSuggests(array $limits, bool $ready = true): void
    {
        Http::fake(['*/predict*' => Http::response(['probability_of_attack' => 0.5, 'ready_to_adjust' => $ready, 'suggested_thresholds' => $limits])]);
    }

    public function test_saving_sets_the_cap_the_effective_limit_and_the_lock(): void
    {
        Sanctum::actingAs($this->user);
        $this->postJson('/api/config', ['pm25_threshold' => 20, 'humidity_locked' => true])
            ->assertOk()
            ->assertJson(['pm25_threshold' => 20, 'pm25_cap' => 20, 'humidity_locked' => true, 'pm25_locked' => false]);
    }

    public function test_the_ai_lowers_limits_below_the_cap_but_never_raises_them(): void
    {
        HardwareConfig::forUser($this->user)->applyUserSettings(['pm25_threshold' => 20]);
        $this->aiSuggests(['pm25_threshold' => 35.0, 'temperature_threshold' => 32.0, 'humidity_threshold' => 73.6, 'mq135_threshold' => 900.0]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $c = HardwareConfig::forUser($this->user)->fresh();
        $this->assertSame(20.0, $c->pm25_threshold, 'AI suggested 35, above the cap of 20: the cap wins');
        $this->assertSame(32.0, $c->temperature_threshold);
        $this->assertSame(73.6, $c->humidity_threshold);
        $this->assertSame(900.0, $c->mq135_threshold);
        $this->assertSame(20.0, $c->pm25_cap, 'the cap itself never changes');
        $this->assertCount(1, $this->published);
    }

    public function test_a_locked_limit_stays_at_the_users_value(): void
    {
        HardwareConfig::forUser($this->user)->applyUserSettings(['humidity_threshold' => 75, 'humidity_locked' => true]);
        $this->aiSuggests(['pm25_threshold' => 35.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 65.0, 'mq135_threshold' => 1000.0]);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(75.0, HardwareConfig::forUser($this->user)->fresh()->humidity_threshold);
    }

    public function test_the_ai_moves_a_limit_at_most_10_percent_per_day(): void
    {
        HardwareConfig::forUser($this->user); // humidity 75
        $this->aiSuggests(['pm25_threshold' => 35.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 60.0, 'mq135_threshold' => 1000.0]);

        $this->artisan('ai:optimize')->assertSuccessful();
        $this->assertSame(67.5, HardwareConfig::forUser($this->user)->fresh()->humidity_threshold, 'first day: 75 - 10 %');

        $this->travel(6)->hours();
        $this->artisan('ai:optimize')->assertSuccessful();
        $this->assertSame(67.5, HardwareConfig::forUser($this->user)->fresh()->humidity_threshold, 'same day: budget used up');

        $this->travel(19)->hours(); // 25 h after the first run: a new day
        $this->artisan('ai:optimize')->assertSuccessful();
        $this->assertSame(60.8, HardwareConfig::forUser($this->user)->fresh()->humidity_threshold, 'second day: 67.5 - 10 %');
    }

    public function test_no_change_before_a_full_day_of_readings(): void
    {
        HardwareConfig::forUser($this->user);
        $this->aiSuggests(['pm25_threshold' => 20.0, 'temperature_threshold' => 30.0, 'humidity_threshold' => 60.0, 'mq135_threshold' => 800.0], ready: false);

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(35.0, HardwareConfig::forUser($this->user)->fresh()->pm25_threshold);
        $this->assertCount(0, $this->published);
    }

    public function test_unchanged_limits_are_not_published_again(): void
    {
        HardwareConfig::forUser($this->user); // ai:optimize only processes accounts that have a config
        $this->aiSuggests(['pm25_threshold' => 25.0, 'temperature_threshold' => 35.0, 'humidity_threshold' => 75.0, 'mq135_threshold' => 1000.0]);

        $this->artisan('ai:optimize')->assertSuccessful();
        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertCount(1, $this->published);
    }
}
