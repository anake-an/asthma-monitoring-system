<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\Patient;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The device named by ?device_id (query or body) if this user may do $ability with it, else 404
     * (a stranger cannot even learn that the id exists). Without an id: the user's most recently
     * seen device, or null when it has none. Abilities: App\Policies\DevicePolicy.
     */
    protected function device(Request $request, string $ability = 'view'): ?Device
    {
        $id = $request->input('device_id');
        if ($id === null || $id === '') {
            $device = $request->user()->accessibleDevices()
                ->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderByDesc('id')
                ->first();
            if ($device && $request->user()->cannot($ability, $device)) {
                abort(404);
            }

            return $device;
        }

        $device = Device::find((int) $id);
        if (!$device || $request->user()->cannot($ability, $device)) {
            abort(404);
        }

        return $device;
    }

    /**
     * The patient named by ?patient_id if this user may do $ability with it, else 404.
     * Without an id: the user's default patient. Abilities: App\Policies\PatientPolicy.
     */
    protected function patient(Request $request, string $ability = 'view'): Patient
    {
        $id = $request->input('patient_id');
        $patient = ($id === null || $id === '') ? $request->user()->defaultPatient() : Patient::find((int) $id);
        if (!$patient || $request->user()->cannot($ability, $patient)) {
            abort(404);
        }

        return $patient;
    }
}
