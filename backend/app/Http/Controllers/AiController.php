<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class AiController extends Controller
{
    /**
     * Fetch predictions from the standalone Python AI Engine container.
     */
    public function getPrediction()
    {
        try {
            // "ai_engine" maps directly to the service name in docker-compose.yml
            $response = Http::timeout(5)->get('http://ai_engine:8000/predict');
            
            if ($response->successful()) {
                return response()->json($response->json());
            }

            return response()->json([
                'error' => 'AI Engine returned an error.',
                'details' => $response->body()
            ], $response->status());

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Could not connect to AI Engine.',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Trigger a model re-training sequence on the AI Engine.
     */
    public function trainModel()
    {
        try {
            $response = Http::timeout(30)->get('http://ai_engine:8000/train');
            return response()->json($response->json(), $response->status());
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to reach AI engine.'], 500);
        }
    }
}
