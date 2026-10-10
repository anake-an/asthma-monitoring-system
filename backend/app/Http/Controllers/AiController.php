<?php

namespace App\Http\Controllers;

use App\Support\AiEngine;
use Illuminate\Http\Request;

/**
 * Proxies the Python AI engine for one room (?device_id, default: the user's most recently seen
 * device). Access is checked here (Controller::device); the engine keeps a room model per device
 * and a risk model per patient (DESIGN_MULTI_PATIENT.md section 5).
 */
class AiController extends Controller
{
    public function getPrediction(Request $request, AiEngine $ai)
    {
        $device = $this->device($request);
        if (!$device) {
            return response()->json(self::learning('No device paired yet.'));
        }

        try {
            $response = $ai->predict($device->id);

            if ($response->successful()) {
                return response()->json($response->json() + ['device_id' => $device->id]);
            }

            // 400 = no model yet / no recent telemetry, 404 = unknown device: a normal state while
            // the room collects data, not an error for the dashboard.
            if (in_array($response->status(), [400, 404], true)) {
                return response()->json(self::learning($response->json('detail') ?? 'Not enough data yet.') + ['device_id' => $device->id]);
            }

            return response()->json([
                'error' => 'AI Engine returned an error.',
                'details' => $response->json('detail') ?? $response->body(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not connect to AI Engine.'], 503);
        }
    }

    /** Retrain the room's model and its child's risk model (e.g. right after a cough was confirmed). */
    public function trainModel(Request $request, AiEngine $ai)
    {
        $device = $this->device($request);
        if (!$device) {
            return response()->json(['message' => 'No device paired yet.'], 404);
        }

        try {
            $room = $ai->trainRoom($device->id);
            $risk = $device->patient_id ? $ai->trainRisk($device->patient_id) : null;

            return response()->json([
                'room' => $room->json(),
                'risk' => $risk?->json(),
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reach AI engine.'], 503);
        }
    }

    private static function learning(string $reason): array
    {
        return [
            'learning' => true,
            'model_stage' => 'Learning mode',
            'probability_of_attack' => null,
            'reason' => $reason,
        ];
    }
}
