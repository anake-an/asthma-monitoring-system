<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\CoughEvent;
use App\Models\HardwareConfig;
use App\Models\InhalerLog;
use App\Models\LimitChange;
use App\Models\TelemetryLog;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Dashboard data, per device (room) or per patient (child). Access goes through
 * Controller::device() / ::patient() and the policies in App\Policies; without an id,
 * the user's most recently seen device or default patient is used.
 */
class DashboardController extends Controller
{
    /**
     * Latest telemetry rows of one device (?device_id, ?limit=1..100, default 100).
     * X-Server-Time (Unix ms) lets the dashboard judge "offline" by the server clock
     * instead of the viewer's computer clock, which may be off by tens of seconds.
     */
    public function getTelemetry(Request $request)
    {
        $limit = min(max((int) $request->query('limit', 100), 1), 100);
        $device = $this->device($request);

        $logs = $device
            ? TelemetryLog::where('device_id', $device->id)->orderBy('recorded_at', 'desc')->take($limit)->get()
            : collect();

        return response()->json($logs)->header('X-Server-Time', (string) now()->getTimestampMs());
    }

    /**
     * Paginated cough events of one device (?device_id), or of every device the user can see
     * when none is given. Each event carries its device's id and name (the room).
     */
    public function getCoughEvents(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 10), 1), 100);
        $deviceIds = $request->filled('device_id')
            ? [$this->device($request)->id]
            : $request->user()->accessibleDevices()->pluck('id');

        $events = CoughEvent::with('device:id,name')
            ->whereIn('device_id', $deviceIds)
            // ?exclude_false_alarms=1: only coughs not marked as false alarm
            ->when($request->boolean('exclude_false_alarms'), fn ($q) => $q->notFalseAlarm())
            // ?unreviewed=1: only coughs nobody has reviewed yet (the dashboard's banner)
            ->when($request->boolean('unreviewed'), fn ($q) => $q->whereNull('is_verified'))
            ->orderBy('recorded_at', 'desc')
            ->paginate($perPage);

        return response()->json($events);
    }

    /**
     * Caregiver feedback on a cough event: a real cough (with or without the emergency inhaler),
     * a false alarm, or null to undo the review. The AI engine, the report and the dashboard's
     * cough banner leave false alarms out. An inhaler dose is logged at the cough's time and
     * removed again when the review changes.
     */
    public function verifyCoughEvent(Request $request, $id)
    {
        $event = CoughEvent::with('device')->find($id);
        $this->authorizeOr404($request, $event?->device, 'logDose');

        $request->validate([
            'is_verified' => 'present|nullable|boolean',
            'inhaler_used' => 'required|boolean',
        ]);
        $verified = $request->input('is_verified') === null ? null : $request->boolean('is_verified');
        $inhalerUsed = $verified === true && $request->boolean('inhaler_used'); // only for a real cough

        $event->update([
            'is_verified' => $verified,
            'inhaler_used' => $inhalerUsed,
        ]);

        if ($inhalerUsed) {
            InhalerLog::firstOrCreate(
                ['cough_event_id' => $event->id],
                [
                    'is_manual' => false,
                    'user_id' => $request->user()->id,
                    // The room's child; NULL in a shared room (not attributed to a child).
                    'patient_id' => $event->device->patient_id,
                    'device_id' => $event->device_id,
                    'administered_at' => $event->recorded_at ?? now(), // when the cough happened, not when it was reviewed
                ]
            );
        } else {
            InhalerLog::where('cough_event_id', $event->id)->delete();
        }

        AuditLog::record($request->user(), 'cough.marked', $event->device->patient_id, $event->device_id, [
            'cough_event_id' => $event->id,
            'verified' => $verified,
            'inhaler_used' => $inhalerUsed,
        ]);

        return response()->json($event->makeHidden('device'));
    }

    public function savePushSubscription(Request $request)
    {
        $request->validate([
            'endpoint' => 'required|url|max:1024',
            'keys.auth' => 'required',
            'keys.p256dh' => 'required',
        ]);

        $request->user()->updatePushSubscription(
            $request->endpoint,
            $request->keys['p256dh'],
            $request->keys['auth']
        );

        return response()->json(['message' => 'Subscription saved']);
    }

    /** Stop pushes to one browser (switched off in the dashboard, or signing out there). */
    public function deletePushSubscription(Request $request)
    {
        $request->validate(['endpoint' => 'required|url|max:1024']);
        $request->user()->deletePushSubscription($request->endpoint);

        return response()->json(['message' => 'Subscription removed']);
    }

    /**
     * Most recent inhaler dose of one patient (?patient_id, default: the user's default patient).
     */
    public function getInhalerStatus(Request $request)
    {
        $patient = $this->patient($request);
        $logs = InhalerLog::where('patient_id', $patient->id);

        $lastLog = (clone $logs)->orderBy('administered_at', 'desc')->first();

        $recentRescueCount = (clone $logs)->where('type', 'rescue')
            ->where('administered_at', '>=', now()->subHours(4))
            ->count();

        return response()->json([
            'patient_id' => $patient->id,
            'last_administered_at' => $lastLog?->administered_at,
            'recent_rescue_count' => $recentRescueCount,
        ]);
    }

    /**
     * Log a manual inhaler dose for a patient (patient_id, default: the user's default patient).
     */
    public function logManualInhaler(Request $request)
    {
        $request->validate([
            'type' => 'required|in:rescue,controller',
            'patient_id' => 'nullable|integer',
        ]);
        $patient = $this->patient($request, 'logDose');

        $log = InhalerLog::create([
            'user_id' => $request->user()->id,
            'patient_id' => $patient->id,
            'is_manual' => true,
            'type' => $request->type,
            'administered_at' => now(), // app clock, matching the "last 4 hours" query above
        ])->refresh();
        AuditLog::record($request->user(), 'dose.logged', $patient->id, null, ['type' => $request->type]);

        // A daily dose clears the "daily dose?" reminder on the child's devices straight away.
        if ($request->type === 'controller') {
            foreach ($patient->devices()->whereNotNull('user_id')->get() as $device) {
                try {
                    app(Mqtt::class)->publishConfig($device, HardwareConfig::forDevice($device));
                } catch (\Throwable $e) {
                    Log::warning("Dose reminder for device {$device->id} not sent: " . $e->getMessage());
                }
            }
        }

        return response()->json([
            'message' => 'Manual inhaler usage logged',
            'last_administered_at' => $log->administered_at,
        ]);
    }

    /**
     * Data for the weekly activity report of one patient (?patient_id): coughs from that child's
     * rooms, that child's doses, and the limit changes of those rooms. Shared rooms are excluded.
     */
    public function getWeeklyReport(Request $request)
    {
        $patient = $this->patient($request);
        $startDate = now()->subDays(7);
        $deviceIds = $patient->devices()->pluck('id');

        $all = fn () => CoughEvent::whereIn('device_id', $deviceIds)->where('recorded_at', '>=', $startDate);
        $coughs = fn () => $all()->notFalseAlarm(); // false alarms are not counted, only reported as a number
        $inhalers = fn () => InhalerLog::where('patient_id', $patient->id)->where('administered_at', '>=', $startDate);

        $rescueDoses = $inhalers()->where('type', 'rescue')->count();
        $controllerDoses = $inhalers()->where('type', 'controller')->count();

        return response()->json([
            'patient' => ['id' => $patient->id, 'name' => $patient->name, 'color' => $patient->color, 'emoji' => $patient->emoji],
            'start_date' => $startDate->toDateString(),
            'end_date' => now()->toDateString(),
            'total_events' => $coughs()->count(),
            'false_alarms' => $all()->where('is_verified', false)->count(),
            'high_severity_events' => $coughs()->where('severity', '>=', CoughEvent::SEVERITY_ALERT)->count(),
            'inhaler_doses' => $rescueDoses + $controllerDoses,
            'rescue_doses' => $rescueDoses,
            'controller_doses' => $controllerDoses,
            'daily_breakdown' => $coughs()
                ->selectRaw('DATE(recorded_at) as date, COUNT(*) as count')
                ->groupBy('date')
                ->orderBy('date')
                ->get(),
            'daily_inhalers' => $inhalers()
                ->selectRaw('DATE(administered_at) as date, type, COUNT(*) as count')
                ->groupBy('date', 'type')
                ->orderBy('date')
                ->get(),
            // Every change of an alert limit in the period, by the AI, the user or a rule, newest first.
            'limit_changes' => LimitChange::with('device:id,name')
                ->whereIn('device_id', $deviceIds)
                ->where('created_at', '>=', $startDate)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(50)
                ->get(['id', 'device_id', 'limit_name', 'old_value', 'new_value', 'source', 'reason', 'created_at'])
                ->map(fn ($c) => $c->only(['limit_name', 'old_value', 'new_value', 'source', 'reason', 'created_at'])
                    + ['device_id' => $c->device_id, 'device_name' => $c->device?->name]),
        ]);
    }
}
