<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Patient;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The device named by ?device_id (query or body) if this user may do $ability with it. A user
     * who cannot even see it gets 404 (a stranger cannot learn that the id exists); one who can
     * see it but lacks the ability gets 403. Without an id: the user's most recently seen device,
     * or null when it has none. Abilities: App\Policies\DevicePolicy.
     */
    protected function device(Request $request, string $ability = 'view'): ?Device
    {
        $id = $request->input('device_id');
        if ($id === null || $id === '') {
            $device = $request->user()->accessibleDevices()
                ->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderByDesc('id')
                ->first();

            return $device ? $this->authorizeOr404($request, $device, $ability) : null;
        }

        return $this->authorizeOr404($request, Device::find((int) $id), $ability);
    }

    /**
     * The patient named by ?patient_id if this user may do $ability with it (404 / 403 as above).
     * Without an id: the user's default patient. Abilities: App\Policies\PatientPolicy.
     */
    protected function patient(Request $request, string $ability = 'view'): Patient
    {
        $id = $request->input('patient_id');
        $patient = ($id === null || $id === '') ? $request->user()->defaultPatient() : Patient::find((int) $id);

        return $this->authorizeOr404($request, $patient, $ability);
    }

    /** @template T of Device|Patient @param T|null $model @return T */
    protected function authorizeOr404(Request $request, $model, string $ability)
    {
        $user = $request->user();
        if (!$model || $user->cannot('view', $model)) {
            abort(404);
        }
        if ($ability !== 'view' && $user->cannot($ability, $model)) {
            abort(403, 'Your role for this child does not allow this.');
        }

        return $model;
    }
}
