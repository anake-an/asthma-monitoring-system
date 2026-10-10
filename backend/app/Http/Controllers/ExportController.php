<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CoughEvent;
use App\Models\InhalerLog;
use App\Models\LimitChange;
use App\Models\Patient;
use App\Models\TelemetryLog;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Data export for one child (PDPA right of access, promised on the privacy page): CSV files of
 * the readings, coughs and limit changes of the child's rooms, and the child's doses. Anyone who
 * can see the child can export it (it is what they already see on screen). Streamed row by row,
 * so a large readings file never sits in memory. Each export is written to the audit log.
 */
class ExportController extends Controller
{
    public const KINDS = ['readings', 'coughs', 'doses', 'limits'];

    public function export(Request $request, $id, string $kind)
    {
        abort_unless(in_array($kind, self::KINDS, true), 404);
        $request->merge(['patient_id' => $id]);
        $patient = $this->patient($request);
        $deviceIds = $patient->devices()->pluck('id');
        $rooms = $patient->devices()->pluck('name', 'id');

        [$header, $rows] = match ($kind) {
            'readings' => [
                ['room', 'recorded_at', 'pm25_ug_m3', 'temperature_c', 'humidity_pct', 'gas_ppm_estimate', 'averaged_from_readings'],
                TelemetryLog::whereIn('device_id', $deviceIds)->orderBy('recorded_at')->lazy(2000)
                    ->map(fn ($r) => [$rooms[$r->device_id] ?? '', self::time($r->recorded_at), $r->pm25_level, $r->temperature, $r->humidity, $r->mq135_level, $r->samples]),
            ],
            'coughs' => [
                ['room', 'recorded_at', 'met_alert_rule', 'detection_strength', 'review', 'inhaler_used'],
                CoughEvent::whereIn('device_id', $deviceIds)->orderBy('recorded_at')->lazy(2000)
                    ->map(fn ($c) => [$rooms[$c->device_id] ?? '', self::time($c->recorded_at), $c->severity >= CoughEvent::SEVERITY_ALERT ? 'yes' : 'no',
                        $c->confidence, $c->is_verified === null ? 'not reviewed' : ($c->is_verified ? 'confirmed' : 'false alarm'), $c->inhaler_used ? 'yes' : 'no']),
            ],
            'doses' => [
                ['administered_at', 'type', 'logged_by', 'how'],
                InhalerLog::with('user:id,name')->where('patient_id', $patient->id)->orderBy('administered_at')->lazy(2000)
                    ->map(fn ($d) => [self::time($d->administered_at), $d->type === 'controller' ? 'daily (controller)' : 'emergency (rescue)',
                        $d->user?->name ?? '', $d->is_manual ? 'logged by hand' : 'from a confirmed cough']),
            ],
            'limits' => [
                ['changed_at', 'room', 'limit', 'old_value', 'new_value', 'by', 'reason'],
                LimitChange::whereIn('device_id', $deviceIds)->orderBy('created_at')->lazy(2000)
                    ->map(fn ($l) => [self::time($l->created_at), $rooms[$l->device_id] ?? '', $l->limit_name, $l->old_value, $l->new_value,
                        ['ai' => 'AI', 'user' => 'user', 'rule' => 'rule'][$l->source] ?? $l->source, $l->reason]),
            ],
        };

        AuditLog::record($request->user(), 'data.exported', $patient->id, null, ['kind' => $kind]);

        $filename = 'respirosync-' . (Str::slug($patient->name) ?: 'child') . "-{$kind}-" . now()->format('Y-m-d') . '.csv';

        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // BOM, so Excel opens names like "Siti" and "µg" correctly
            fputcsv($out, $header);
            foreach ($rows as $row) {
                fputcsv($out, array_map(self::safeCell(...), $row));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private static function time($value): string
    {
        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i:s') : (string) $value;
    }

    /** Spreadsheet formula injection: a text cell starting with = + - @ is shown as text. */
    private static function safeCell($value)
    {
        return is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@'], true) ? "'" . $value : $value;
    }
}
