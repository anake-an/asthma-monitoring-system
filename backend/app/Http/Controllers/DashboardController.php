<?php

namespace App\Http\Controllers;

use App\Models\TelemetryLog;
use App\Models\CoughEvent;
use Illuminate\Http\Request;

class DashboardController
{
    /**
     * Fetch the latest 100 environment telemetry logs.
     * These logs are continuously populated by the MQTT background worker.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getTelemetry(Request $request)
    {
        $deviceIds = $request->user()->devices()->pluck('id');
        
        $logs = TelemetryLog::whereIn('device_id', $deviceIds)
            ->orderBy('recorded_at', 'desc')
            ->take(100)
            ->get();
            
        return response()->json($logs);
    }

    /**
     * Retrieve a paginated list of cough events detected by the AI Engine.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getCoughEvents(Request $request)
    {
        $deviceIds = $request->user()->devices()->pluck('id');
        $perPage = $request->get('per_page', 10);
        
        $events = CoughEvent::whereIn('device_id', $deviceIds)
            ->orderBy('recorded_at', 'desc')
            ->paginate($perPage);
            
        return response()->json($events);
    }

    /**
     * Verify a cough event and log whether an inhaler was used.
     * This data acts as the feedback loop to re-train the AI Engine.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function verifyCoughEvent(Request $request, $id)
    {
        $event = CoughEvent::findOrFail($id);
        
        $request->validate([
            'is_verified' => 'required|boolean',
            'inhaler_used' => 'required|boolean',
        ]);

        $event->update([
            'is_verified' => $request->is_verified,
            'inhaler_used' => $request->inhaler_used,
        ]);

        if ($request->inhaler_used) {
            \App\Models\InhalerLog::firstOrCreate(
                ['cough_event_id' => $event->id],
                ['is_manual' => false]
            );
        } else {
            \App\Models\InhalerLog::where('cough_event_id', $event->id)->delete();
        }

        // In the future, this is where we would dispatch a job to retrain or update the AI model
        // with the new validated dataset.

        return response()->json($event);
    }

    public function savePushSubscription(Request $request)
    {
        $request->validate([
            'endpoint' => 'required',
            'keys.auth' => 'required',
            'keys.p256dh' => 'required'
        ]);

        $request->user()->updatePushSubscription(
            $request->endpoint,
            $request->keys['p256dh'],
            $request->keys['auth']
        );

        return response()->json(['message' => 'Subscription saved']);
    }

    /**
     * Fetch the most recent inhaler administration time.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getInhalerStatus(Request $request)
    {
        $lastLog = \App\Models\InhalerLog::orderBy('administered_at', 'desc')->first();
        
        $recentRescueCount = \App\Models\InhalerLog::where('type', 'rescue')
            ->where('administered_at', '>=', now()->subHours(4))
            ->count();

        return response()->json([
            'last_administered_at' => $lastLog ? $lastLog->administered_at : null,
            'recent_rescue_count' => $recentRescueCount
        ]);
    }

    /**
     * Log a manual administration of the inhaler.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function logManualInhaler(Request $request)
    {
        $request->validate([
            'type' => 'required|in:rescue,controller'
        ]);

        $log = \App\Models\InhalerLog::create([
            'is_manual' => true,
            'type' => $request->type,
        ]);
        
        return response()->json([
            'message' => 'Manual inhaler usage logged',
            'last_administered_at' => $log->administered_at
        ]);
    }

    /**
     * Generate the data payload for the weekly PDF pediatric report.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getWeeklyReport(Request $request)
    {
        $startDate = now()->subDays(7);
        
        $totalEvents = CoughEvent::where('recorded_at', '>=', $startDate)->count();
        $highSeverity = CoughEvent::where('recorded_at', '>=', $startDate)->where('severity', '>=', 7)->count();
        $rescueDoses = \App\Models\InhalerLog::where('administered_at', '>=', $startDate)->where('type', 'rescue')->count();
        $controllerDoses = \App\Models\InhalerLog::where('administered_at', '>=', $startDate)->where('type', 'controller')->count();
        $inhalerDoses = $rescueDoses + $controllerDoses;
        
        $dailyEvents = CoughEvent::where('recorded_at', '>=', $startDate)
            ->selectRaw('DATE(recorded_at) as date, COUNT(*) as count')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $dailyInhalers = \App\Models\InhalerLog::where('administered_at', '>=', $startDate)
            ->selectRaw('DATE(administered_at) as date, type, COUNT(*) as count')
            ->groupBy('date', 'type')
            ->orderBy('date')
            ->get();

        return response()->json([
            'start_date' => $startDate->toDateString(),
            'end_date' => now()->toDateString(),
            'total_events' => $totalEvents,
            'high_severity_events' => $highSeverity,
            'inhaler_doses' => $inhalerDoses,
            'rescue_doses' => $rescueDoses,
            'controller_doses' => $controllerDoses,
            'daily_breakdown' => $dailyEvents,
            'daily_inhalers' => $dailyInhalers
        ]);
    }
}
