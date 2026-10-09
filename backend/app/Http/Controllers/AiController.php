<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Proxies the Python AI engine. Every call is scoped to the authenticated user:
 * the engine only reads that user's devices and keeps one model per user.
 */
class AiController extends Controller
{
    public function getPrediction(Request $request)
    {
        try {
            $response = Http::timeout(5)->get(config('services.ai_engine.url') . '/predict', [
                'user_id' => $request->user()->id,
            ]);

            if ($response->successful()) {
                return response()->json($response->json());
            }

            // 400 = no model yet / no recent telemetry, 404 = no paired device: a normal
            // state while the account collects data, not an error for the dashboard.
            if (in_array($response->status(), [400, 404], true)) {
                return response()->json([
                    'learning' => true,
                    'model_stage' => 'Learning mode',
                    'probability_of_attack' => null,
                    'reason' => $response->json('detail') ?? 'Not enough data yet.',
                ]);
            }

            return response()->json([
                'error' => 'AI Engine returned an error.',
                'details' => $response->json('detail') ?? $response->body(),
            ], $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Could not connect to AI Engine.'], 503);
        }
    }

    public function trainModel(Request $request)
    {
        try {
            $response = Http::timeout(30)->get(config('services.ai_engine.url') . '/train', [
                'user_id' => $request->user()->id,
            ]);

            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reach AI engine.'], 503);
        }
    }
}
