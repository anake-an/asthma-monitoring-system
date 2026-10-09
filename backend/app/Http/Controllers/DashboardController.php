<?php

namespace App\Http\Controllers;

use App\Models\CoughEvent;
use App\Models\InhalerLog;
use App\Models\TelemetryLog;
use Illuminate\Http\Request;

/**
 * Every query here is scoped to the authenticated account: telemetry and cough
 * events through the user's devices, inhaler logs through user_id.
 */
class DashboardController extends Controller
{
    /**
     * Latest 100 telemetry rows from the user's devices.
     */
    public function getTelemetry(Request $request)
    {
        $logs = TelemetryLog::whereIn('device_id', $this->deviceIds($request))
            ->orderBy('recorded_at', 'desc')
            ->take(100)
            ->get();

        return response()->json($logs);
    }

    /**
     * Paginated cough events from the user's devices.
     */
    public function getCoughEvents(Request $request)
    {
        $perPage = min(max((int) $request->get('per_page', 10), 1), 100);

        $events = CoughEvent::whereIn('device_id', $this->deviceIds($request))
            ->orderBy('recorded_at', 'desc')
            ->paginate($perPage);

        return response()->json($events);
    }

    /**
     * Caregiver feedback on a cough event (confirmed / false alarm, inhaler used).
     * The AI engine excludes events marked as false alarms from training.
     */
    public function verifyCoughEvent(Request $request, $id)
    {
        $event = CoughEvent::whereIn('device_id', $this->deviceIds($request))->findOrFail($id);

        $request->validate([
            'is_verified' => 'required|boolean',
            'inhaler_used' => 'required|boolean',
        ]);

        $event->update([
            'is_verified' => $request->boolean('is_verified'),
            'inhaler_used' => $request->boolean('inhaler_used'),
        ]);

        if ($request->boolean('inhaler_used')) {
            InhalerLog::firstOrCreate(
                ['cough_event_id' => $event->id],
                ['is_manual' => false, 'user_id' => $request->user()->id, 'device_id' => $event->device_id, 'administered_at' => now()]
            );
        } else {
            InhalerLog::where('cough_event_id', $event->id)->delete();
        }

        return response()->json($event);
    }

    public function savePushSubscription(Request $request)
    {
        $request->validate([
            'endpoint' => 'required',
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

    /**
     * Most recent inhaler administration for this account.
     */
    public function getInhalerStatus(Request $request)
    {
        $logs = $request->user()->inhalerLogs();

        $lastLog = (clone $logs)->orderBy('administered_at', 'desc')->first();

        $recentRescueCount = (clone $logs)->where('type', 'rescue')
            ->where('administered_at', '>=', now()->subHours(4))
            ->count();

        return response()->json([
            'last_administered_at' => $lastLog?->administered_at,
            'recent_rescue_count' => $recentRescueCount,
        ]);
    }

    /**
     * Log a manual inhaler dose for this account.
     */
    public function logManualInhaler(Request $request)
    {
        $request->validate([
            'type' => 'required|in:rescue,controller',
        ]);

        $log = InhalerLog::create([
            'user_id' => $request->user()->id,
            'is_manual' => true,
            'type' => $request->type,
            'administered_at' => now(), // app clock, matching the "last 4 hours" query above
        ])->refresh();

        return response()->json([
            'message' => 'Manual inhaler usage logged',
            'last_administered_at' => $log->administered_at,
        ]);
    }

    /**
     * Data for the weekly activity report, for this account only.
     */
    public function getWeeklyReport(Request $request)
    {
        $startDate = now()->subDays(7);
        $deviceIds = $this->deviceIds($request);
        $userId = $request->user()->id;

        $coughs = fn () => CoughEvent::whereIn('device_id', $deviceIds)->where('recorded_at', '>=', $startDate);
        $inhalers = fn () => InhalerLog::where('user_id', $userId)->where('administered_at', '>=', $startDate);

        $rescueDoses = $inhalers()->where('type', 'rescue')->count();
        $controllerDoses = $inhalers()->where('type', 'controller')->count();

        return response()->json([
            'start_date' => $startDate->toDateString(),
            'end_date' => now()->toDateString(),
            'total_events' => $coughs()->count(),
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
        ]);
    }

    private function deviceIds(Request $request)
    {
        return $request->user()->devices()->pluck('id');
    }
}
