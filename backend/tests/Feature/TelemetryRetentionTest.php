<?php

namespace Tests\Feature;

use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\TelemetryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Retention agreed on 2026-10-10: every reading for 7 days, then one 10-minute average per device,
 * deleted after a year. Coughs and other records are never touched.
 */
class TelemetryRetentionTest extends TestCase
{
    use RefreshDatabase;

    private Device $bedroom;
    private Device $nursery;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 10)->setTime(3, 30));
        $user = User::factory()->create();
        $this->bedroom = Device::create(['user_id' => $user->id, 'device_token' => 'BED001']);
        $this->nursery = Device::create(['user_id' => $user->id, 'device_token' => 'NUR001']);
    }

    private function reading(Device $d, string $at, float $pm25, ?float $temp = 30.0, ?float $hum = 70.0, ?float $gas = 450.0): void
    {
        TelemetryLog::create(['device_id' => $d->id, 'recorded_at' => $at, 'pm25_level' => $pm25,
            'temperature' => $temp, 'humidity' => $hum, 'mq135_level' => $gas]);
    }

    public function test_old_readings_become_ten_minute_averages_recent_ones_stay(): void
    {
        // 10 days ago: three readings in the 14:00 window (one with a failed DHT22), one at 14:10.
        $this->reading($this->bedroom, '2026-09-30 14:01:00', 10, 30, 70);
        $this->reading($this->bedroom, '2026-09-30 14:05:00', 20, null, null);
        $this->reading($this->bedroom, '2026-09-30 14:09:57', 30, 32, 72);
        $this->reading($this->bedroom, '2026-09-30 14:10:03', 50);
        // Yesterday: kept as is.
        $this->reading($this->bedroom, '2026-10-09 14:01:00', 5);
        $this->reading($this->bedroom, '2026-10-09 14:01:03', 6);

        $this->artisan('telemetry:prune')->assertSuccessful();

        $averages = TelemetryLog::where('device_id', $this->bedroom->id)->whereNotNull('samples')->orderBy('recorded_at')->get();
        $this->assertCount(2, $averages);
        $this->assertSame('2026-09-30 14:00:00', $averages[0]->recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame(3, $averages[0]->samples);
        $this->assertSame(20.0, $averages[0]->pm25_level);
        $this->assertSame(31.0, $averages[0]->temperature, 'the failed reading is left out, not counted as 0');
        $this->assertSame(71.0, $averages[0]->humidity);
        $this->assertSame('2026-09-30 14:10:00', $averages[1]->recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame(50.0, $averages[1]->pm25_level);

        $this->assertSame(2, TelemetryLog::where('device_id', $this->bedroom->id)->whereNull('samples')->count(), 'the last 7 days stay raw');
    }

    public function test_readings_older_than_a_year_are_deleted_averages_included(): void
    {
        $this->reading($this->bedroom, '2025-09-01 10:00:00', 10);
        TelemetryLog::create(['device_id' => $this->bedroom->id, 'recorded_at' => '2025-09-01 10:10:00', 'pm25_level' => 12, 'samples' => 200]);
        $this->reading($this->bedroom, '2025-11-01 10:00:00', 14); // 11 months: averaged, kept

        $this->artisan('telemetry:prune')->assertSuccessful();

        $left = TelemetryLog::all();
        $this->assertCount(1, $left);
        $this->assertSame('2025-11-01 10:00:00', $left[0]->recorded_at->format('Y-m-d H:i:s'));
        $this->assertSame(1, $left[0]->samples);
    }

    public function test_devices_are_averaged_separately_and_a_second_run_changes_nothing(): void
    {
        $this->reading($this->bedroom, '2026-09-20 08:00:00', 10);
        $this->reading($this->nursery, '2026-09-20 08:00:00', 40);
        $this->reading($this->nursery, '2026-09-21 23:59:59', 44); // another day, same device

        $this->artisan('telemetry:prune')->assertSuccessful();
        $snapshot = TelemetryLog::orderBy('id')->get(['device_id', 'recorded_at', 'pm25_level', 'samples'])->toArray();
        $this->artisan('telemetry:prune')->assertSuccessful();

        $this->assertSame($snapshot, TelemetryLog::orderBy('id')->get(['device_id', 'recorded_at', 'pm25_level', 'samples'])->toArray());
        $this->assertSame([10.0], TelemetryLog::where('device_id', $this->bedroom->id)->pluck('pm25_level')->all());
        $this->assertSame([40.0, 44.0], TelemetryLog::where('device_id', $this->nursery->id)->orderBy('recorded_at')->pluck('pm25_level')->all());
    }

    public function test_coughs_are_never_touched(): void
    {
        CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 3, 'recorded_at' => '2024-01-01 10:00:00']);
        $this->reading($this->bedroom, '2024-01-01 10:00:00', 10);

        $this->artisan('telemetry:prune')->assertSuccessful();

        $this->assertSame(0, TelemetryLog::count());
        $this->assertSame(1, CoughEvent::count());
    }

    public function test_the_dashboard_still_gets_the_newest_reading(): void
    {
        $this->reading($this->bedroom, '2026-09-01 10:00:00', 99);
        $this->reading($this->bedroom, '2026-10-10 03:29:57', 7);
        $this->artisan('telemetry:prune')->assertSuccessful();

        \Laravel\Sanctum\Sanctum::actingAs($this->bedroom->user);
        $this->assertSame(7.0, (float) $this->getJson("/api/telemetry?limit=1&device_id={$this->bedroom->id}")->json('0.pm25_level'));
    }
}
