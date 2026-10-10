<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\CoughEvent;
use App\Models\Device;
use App\Models\InhalerLog;
use App\Models\LimitChange;
use App\Models\TelemetryLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Data export for one child (PDPA right of access): CSV per kind, only that child's data.
 */
class DataExportTest extends TestCase
{
    use RefreshDatabase;

    private User $parent;
    private Device $bedroom;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parent = User::factory()->create();
        $this->parent->defaultPatient()->update(['name' => 'Aiman Hakim']);
        $this->bedroom = Device::create(['user_id' => $this->parent->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        Sanctum::actingAs($this->parent);
    }

    /** @return array<int, array<int, string>> the CSV rows (header first) */
    private function csv(string $kind): array
    {
        $patientId = $this->parent->defaultPatient()->id;
        $response = $this->get("/api/patients/{$patientId}/export/{$kind}")->assertOk();
        $this->assertStringStartsWith('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString("respirosync-aiman-hakim-{$kind}-", $response->headers->get('Content-Disposition'));

        $body = ltrim($response->streamedContent(), "\xEF\xBB\xBF");

        return array_map('str_getcsv', array_values(array_filter(explode("\n", $body))));
    }

    public function test_readings_of_the_childs_rooms_only(): void
    {
        TelemetryLog::create(['device_id' => $this->bedroom->id, 'pm25_level' => 8.5, 'temperature' => 30, 'humidity' => 70, 'mq135_level' => 450, 'recorded_at' => '2026-10-10 01:00:00']);
        TelemetryLog::create(['device_id' => $this->bedroom->id, 'pm25_level' => 9, 'temperature' => null, 'humidity' => null, 'mq135_level' => 460, 'recorded_at' => '2026-10-01 01:00:00', 'samples' => 200]);
        $shared = Device::create(['user_id' => $this->parent->id, 'patient_id' => null, 'device_token' => 'SHR001', 'name' => 'Living']);
        TelemetryLog::create(['device_id' => $shared->id, 'pm25_level' => 50, 'recorded_at' => '2026-10-10 01:00:00']);

        $rows = $this->csv('readings');

        $this->assertSame(['room', 'recorded_at', 'pm25_ug_m3', 'temperature_c', 'humidity_pct', 'gas_ppm_estimate', 'averaged_from_readings'], $rows[0]);
        $this->assertCount(3, $rows, 'header + the two bedroom rows; the shared room is not this child\'s');
        $this->assertSame(['Bedroom', '2026-10-01 01:00:00', '9', '', '', '460', '200'], $rows[1]);
        $this->assertSame(['Bedroom', '2026-10-10 01:00:00', '8.5', '30', '70', '450', ''], $rows[2]);
    }

    public function test_coughs_doses_and_limit_changes(): void
    {
        CoughEvent::create(['device_id' => $this->bedroom->id, 'severity' => 3, 'confidence' => 0.9, 'is_verified' => false, 'recorded_at' => '2026-10-10 02:00:00']);
        InhalerLog::create(['user_id' => $this->parent->id, 'type' => 'rescue', 'is_manual' => true, 'administered_at' => '2026-10-10 02:05:00']);
        LimitChange::create(['user_id' => $this->parent->id, 'device_id' => $this->bedroom->id, 'limit_name' => 'pm25', 'old_value' => 35, 'new_value' => 31.5,
            'source' => 'ai', 'reason' => 'Room unusual (Stage 1)', 'created_at' => '2026-10-10 03:00:00']);

        $this->assertSame(['Bedroom', '2026-10-10 02:00:00', 'yes', '0.9', 'false alarm', 'no'], $this->csv('coughs')[1]);
        $this->assertSame(['2026-10-10 02:05:00', 'emergency (rescue)', $this->parent->name, 'logged by hand'], $this->csv('doses')[1]);
        $this->assertSame(['2026-10-10 03:00:00', 'Bedroom', 'pm25', '35', '31.5', 'AI', 'Room unusual (Stage 1)'], $this->csv('limits')[1]);
        $this->assertSame(3, AuditLog::where('action', 'data.exported')->count());
    }

    public function test_a_cell_that_looks_like_a_formula_stays_text(): void
    {
        $this->bedroom->update(['name' => '=HYPERLINK("http://evil.test")']);
        TelemetryLog::create(['device_id' => $this->bedroom->id, 'pm25_level' => 1, 'recorded_at' => '2026-10-10 01:00:00']);

        $this->assertSame("'=HYPERLINK(\"http://evil.test\")", $this->csv('readings')[1][0]);
    }

    public function test_strangers_get_404_and_unknown_kinds_too(): void
    {
        $patientId = $this->parent->defaultPatient()->id;
        $this->get("/api/patients/{$patientId}/export/passwords")->assertNotFound();

        Sanctum::actingAs(User::factory()->create());
        $this->get("/api/patients/{$patientId}/export/readings")->assertNotFound();
    }
}
