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

    /**
     * Right to erasure: delete the model files of deleted rooms / children. Never blocks a deletion:
     * an unreachable engine is logged, and a model whose room or child no longer exists is never read.
     */
    public function forget(iterable $deviceIds = [], iterable $patientIds = []): void
    {
        $query = collect($deviceIds)->map(fn ($id) => 'device_id=' . (int) $id)
            ->merge(collect($patientIds)->map(fn ($id) => 'patient_id=' . (int) $id));
        if ($query->isEmpty()) {
            return;
        }
        try {
            // Repeated keys (device_id=1&device_id=2), the way the engine reads lists.
            Http::timeout(10)->delete(config('services.ai_engine.url') . '/models?' . $query->join('&'));
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('AI model files not deleted', ['error' => $e->getMessage()]);
        }
    }

    private function get(string $path, array $query, int $timeout): Response
    {
        return Http::timeout($timeout)->get(config('services.ai_engine.url') . $path, $query);
    }
}
