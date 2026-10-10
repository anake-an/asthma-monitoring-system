<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Models\HardwareConfig;
use App\Models\Patient;
use App\Support\AiEngine;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Devices (one per room). Pairing, renaming, assigning to a child and removal need "configure"
 * (DevicePolicy); listing shows every device the user can see.
 */
class DeviceController extends Controller
{
    public function generateToken(Request $request, Mqtt $mqtt)
    {
        $request->validate(['patient_id' => 'nullable|integer']);
        $user = $request->user();
        // The new room belongs to the chosen child (one the user owns). Without one: the user's own
        // first child, created as "My child" if they have none yet (never a child shared with them).
        if (!$request->filled('patient_id')) {
            $request->merge(['patient_id' => $user->defaultPatient()->id]);
        }
        $patient = $this->patient($request, 'manage');

        // 6-character uppercase token, also used as the device's MQTT client id
        do {
            $token = strtoupper(Str::random(6));
        } while (Device::where('device_token', $token)->exists());

        $device = Device::create([
            'user_id' => $user->id,
            'patient_id' => $patient->id,
            'device_token' => $token,
            'name' => 'New RespiroSync ESP32',
            'status' => 'pending',
        ]);
        AuditLog::record($user, 'device.paired', $patient->id, $device->id, ['token' => $token]);

        // Retained, so the ESP32 gets its room's thresholds on its very first connect.
        try {
            $mqtt->publishConfig($device, HardwareConfig::forDevice($device));
        } catch (\Throwable $e) {
            Log::error('Failed to publish initial config', ['device_id' => $device->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Token generated successfully',
            'token' => $token,
            'device' => $device,
        ]);
    }

    /**
     * Every device this user can see, most recently seen first, with its child and whether this
     * user may change it.
     */
    public function getDevices(Request $request)
    {
        $user = $request->user();
        $devices = $user->accessibleDevices()
            ->with('patient:id,name,color,emoji')
            ->orderByRaw('last_seen_at IS NULL')->orderByDesc('last_seen_at')->orderByDesc('created_at')
            ->get()
            ->map(fn (Device $d) => $d->toArray() + ['can_configure' => $user->can('configure', $d), 'ota' => $d->otaState()]);
        $latest = FirmwareRelease::latest(); // owners can update a device that runs an older version

        return response()->json(['devices' => $devices, 'latest_firmware' => $latest ? ['version' => $latest->version, 'notes' => $latest->notes] : null]);
    }

    /** Rename a device (its room) or move it to another child / a shared room (patient_id null). */
    public function updateDevice(Request $request, $id)
    {
        $request->merge(['device_id' => $id]);
        $device = $this->device($request, 'configure');

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:40',
            'patient_id' => 'sometimes|nullable|integer',
        ]);

        if (array_key_exists('patient_id', $validated) && $validated['patient_id'] !== null) {
            // Only into a child this user owns.
            $this->authorizeOr404($request, Patient::find($validated['patient_id']), 'manage');
        }

        $before = ['name' => $device->name, 'patient' => $device->patient?->name, 'patient_id' => $device->patient_id];
        $device->update($validated);
        $device->load('patient:id,name,color,emoji');

        if ($device->name !== $before['name']) {
            AuditLog::record($request->user(), 'device.renamed', $device->patient_id, $device->id, ['from' => $before['name'], 'to' => $device->name]);
        }
        if ((int) $device->patient_id !== (int) $before['patient_id']) {
            $moved = ['room' => $device->name, 'from' => $before['patient'] ?? 'shared room', 'to' => $device->patient?->name ?? 'shared room'];
            // Logged for both children, so each one's history shows the room arriving or leaving.
            foreach (array_unique(array_filter([$before['patient_id'], $device->patient_id])) as $patientId) {
                AuditLog::record($request->user(), 'device.moved', (int) $patientId, $device->id, $moved);
            }
        }

        return response()->json($device->fresh()->load('patient:id,name,color,emoji'));
    }

    public function deleteDevice(Request $request, Mqtt $mqtt, $id)
    {
        $request->merge(['device_id' => $id]);
        $device = $this->device($request, 'configure');

        try {
            $mqtt->sendCommand($device, 'factory_reset');
            $mqtt->clearConfig($device);
        } catch (\Throwable $e) {
            Log::error('Failed to publish factory reset: ' . $e->getMessage());
        }

        AuditLog::record($request->user(), 'device.removed', $device->patient_id, $device->id, ['room' => $device->name, 'token' => $device->device_token]);
        $device->delete();
        app(AiEngine::class)->forget([$device->id]); // its room model too

        return response()->json(['message' => 'Device removed successfully']);
    }
}
