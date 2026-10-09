<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\InhalerLog;
use App\Models\LimitChange;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Missed daily dose rule (DESIGN_MULTI_PATIENT.md 5.5): an account that uses a daily (controller)
 * inhaler but logged none in the last 26 h gets unlocked limits 15 % lower until a dose is logged.
 */
class MissedDoseRuleTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        Device::create(['user_id' => $this->user->id, 'device_token' => 'DOSE01']);
        HardwareConfig::forUser($this->user); // 35 / 35 / 75 / 1000
        Http::fake(['*/predict*' => Http::response(['detail' => 'Model not trained yet'], 400)]); // AI learning
    }

    private function controllerDose(string $ago): void
    {
        InhalerLog::create(['user_id' => $this->user->id, 'is_manual' => true, 'type' => 'controller', 'administered_at' => now()->sub($ago)]);
    }

    private function config(): HardwareConfig
    {
        return HardwareConfig::forUser($this->user)->fresh();
    }

    public function test_a_missed_daily_dose_lowers_unlocked_limits_until_one_is_logged(): void
    {
        HardwareConfig::forUser($this->user)->update(['humidity_locked' => true]);
        $this->controllerDose('30 hours');

        $this->artisan('ai:optimize')->assertSuccessful();

        $c = $this->config();
        $this->assertSame(29.8, $c->pm25_threshold, '35 - 15 %');
        $this->assertSame(850.0, $c->mq135_threshold);
        $this->assertSame(75.0, $c->humidity_threshold, 'locked limits are left alone');
        $this->assertSame(35.0, $c->pm25_cap, 'your own value does not change');
        $this->assertSame(3, LimitChange::where('source', 'rule')->where('reason', HardwareConfig::MISSED_DOSE_ON)->count());
        $this->assertCount(1, $this->published);

        // Still missed 5 minutes later: no compounding, nothing new logged or sent.
        $this->travel(5)->minutes();
        $this->artisan('ai:optimize')->assertSuccessful();
        $this->assertSame(29.8, $this->config()->pm25_threshold);
        $this->assertSame(3, LimitChange::count());
        $this->assertCount(1, $this->published);

        $this->controllerDose('1 minute');
        $this->artisan('ai:optimize')->assertSuccessful();

        $c = $this->config();
        $this->assertSame(35.0, $c->pm25_threshold);
        $this->assertNull($c->missed_dose_base);
        $this->assertSame(3, LimitChange::where('reason', HardwareConfig::MISSED_DOSE_OFF)->count());
        $this->assertCount(2, $this->published);
    }

    public function test_accounts_without_daily_doses_are_never_affected(): void
    {
        $this->controllerDose('8 days'); // stopped using it more than a week ago

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(35.0, $this->config()->pm25_threshold);
        $this->assertSame(0, LimitChange::count());
    }

    public function test_a_dose_within_26_hours_is_not_missed(): void
    {
        $this->controllerDose('25 hours');

        $this->artisan('ai:optimize')->assertSuccessful();

        $this->assertSame(35.0, $this->config()->pm25_threshold);
    }

    public function test_a_new_value_while_the_rule_is_on_is_also_lowered(): void
    {
        $this->controllerDose('30 hours');
        $this->artisan('ai:optimize')->assertSuccessful(); // pm25 29.8, temperature 29.8

        Sanctum::actingAs($this->user);
        $this->postJson('/api/config', ['pm25_threshold' => 20])->assertOk();

        $c = $this->config();
        $this->assertSame(17.0, $c->pm25_threshold, '20 - 15 %');
        $this->assertSame(20.0, $c->pm25_cap);
        $this->assertSame(29.8, $c->temperature_threshold, 'untouched limits are not lowered twice');
        $this->assertSame(['pm25' => 20.0, 'temperature' => 35.0, 'humidity' => 75.0, 'mq135' => 1000.0], $c->missed_dose_base);

        $this->controllerDose('1 minute');
        $this->artisan('ai:optimize')->assertSuccessful();
        $this->assertSame(20.0, $this->config()->pm25_threshold, 'back to the new value once a dose is logged');
    }

    public function test_turning_ai_off_restores_your_limits_and_ends_the_rule(): void
    {
        $this->controllerDose('30 hours');
        $this->artisan('ai:optimize')->assertSuccessful();

        Sanctum::actingAs($this->user);
        $this->postJson('/api/config', ['ai_optimization_enabled' => false])->assertOk();

        $c = $this->config();
        $this->assertSame(35.0, $c->pm25_threshold);
        $this->assertSame(1000.0, $c->mq135_threshold);
        $this->assertNull($c->missed_dose_base);
        $this->assertSame(4, LimitChange::where('reason', 'AI optimization turned off: back to your limits')->count());
    }
}
