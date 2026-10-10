<?php

namespace App\Console\Commands;

use App\Models\TelemetryLog;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Telemetry retention, nightly (routes/console.php). Agreed with the project owner on 2026-10-10:
 *   - the last RAW_DAYS days: every reading, unchanged
 *   - older: one average per device per BUCKET_MINUTES (the AI averages readings into the same
 *     10-minute windows before learning, so it trains the same way on them)
 *   - older than KEEP_DAYS: deleted
 * Only telemetry_logs; coughs, doses, limit changes and the audit log are never touched.
 * Safe to run again: averages (samples not NULL) are never averaged twice.
 */
class TelemetryPrune extends Command
{
    protected $signature = 'telemetry:prune';

    protected $description = 'Average readings older than 7 days into 10-minute rows and delete those older than a year';

    public function handle()
    {
        $now = now();
        $deleted = TelemetryLog::where('recorded_at', '<', $now->copy()->subDays(TelemetryLog::KEEP_DAYS))->delete();
        $this->info("Deleted {$deleted} reading(s) older than " . TelemetryLog::KEEP_DAYS . ' days.');

        $rawCutoff = $now->copy()->subDays(TelemetryLog::RAW_DAYS)->startOfDay();
        $deviceIds = TelemetryLog::whereNull('samples')->where('recorded_at', '<', $rawCutoff)->distinct()->pluck('device_id');
        foreach ($deviceIds as $deviceId) {
            [$replaced, $averages] = $this->averageDevice($deviceId, $rawCutoff);
            $this->info("Device {$deviceId}: {$replaced} reading(s) -> {$averages} ten-minute average(s).");
        }

        return self::SUCCESS;
    }

    /** Replace one device's raw readings before $cutoff by averages, one day at a time. */
    private function averageDevice($deviceId, Carbon $cutoff): array
    {
        $replaced = $averages = 0;
        $raw = fn () => TelemetryLog::where('device_id', $deviceId)->whereNull('samples')->where('recorded_at', '<', $cutoff);

        while ($first = $raw()->min('recorded_at')) {
            $dayStart = Carbon::parse($first)->startOfDay();
            $dayEnd = $dayStart->copy()->addDay()->min($cutoff);
            $rows = $raw()->where('recorded_at', '>=', $dayStart)->where('recorded_at', '<', $dayEnd)
                ->orderBy('recorded_at')->get(['id', 'pm25_level', 'temperature', 'humidity', 'mq135_level', 'recorded_at']);

            $buckets = $rows->groupBy(fn (TelemetryLog $r) => self::bucketStart($r->recorded_at)->getTimestamp());
            DB::transaction(function () use ($deviceId, $buckets, $rows) {
                foreach ($buckets as $start => $group) {
                    TelemetryLog::create([
                        'device_id' => $deviceId,
                        'recorded_at' => Carbon::createFromTimestamp($start, config('app.timezone')),
                        // A failed sensor stays NULL: averages only over the readings that exist.
                        'pm25_level' => round($group->avg('pm25_level'), 2),
                        'temperature' => self::average($group, 'temperature'),
                        'humidity' => self::average($group, 'humidity'),
                        'mq135_level' => self::average($group, 'mq135_level'),
                        'samples' => $group->count(),
                    ]);
                }
                foreach ($rows->pluck('id')->chunk(1000) as $ids) {
                    TelemetryLog::whereIn('id', $ids)->delete();
                }
            });

            $replaced += $rows->count();
            $averages += $buckets->count();
        }

        return [$replaced, $averages];
    }

    /** Start of the BUCKET_MINUTES window a reading falls in (clock-aligned: 10:00, 10:10, ...). */
    public static function bucketStart(Carbon $time): Carbon
    {
        $seconds = TelemetryLog::BUCKET_MINUTES * 60;

        return Carbon::createFromTimestamp(intdiv($time->getTimestamp(), $seconds) * $seconds, config('app.timezone'));
    }

    private static function average($group, string $column): ?float
    {
        $values = $group->pluck($column)->filter(fn ($v) => $v !== null);

        return $values->isEmpty() ? null : round($values->avg(), 2);
    }
}
