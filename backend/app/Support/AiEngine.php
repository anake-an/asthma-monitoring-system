<?php

namespace App\Support;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Python AI engine (ai_engine/main.py), two levels (DESIGN_MULTI_PATIENT.md section 5):
 * a room model per device and a risk model per patient. Callers check access first; the engine
 * trusts the ids it gets and is only reachable inside the Docker network.
 */
class AiEngine
{
    /** Prediction for one room: its own baseline, or its child's risk model. */
    public function predict(int $deviceId): Response
    {
        return $this->get('/predict', ['device_id' => $deviceId], 10);
    }

    /** Learn what is normal for one room (needs only telemetry). */
    public function trainRoom(int $deviceId): Response
    {
        return $this->get('/train/room', ['device_id' => $deviceId], 30);
    }

    /** Learn what precedes one child's episodes, from their own rooms (never a shared room). */
    public function trainRisk(int $patientId): Response
    {
        return $this->get('/train/risk', ['patient_id' => $patientId], 60);
    }

    private function get(string $path, array $query, int $timeout): Response
    {
        return Http::timeout($timeout)->get(config('services.ai_engine.url') . $path, $query);
    }
}
