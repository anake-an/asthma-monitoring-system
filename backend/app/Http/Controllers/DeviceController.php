<?php

namespace App\Http\Controllers;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class DeviceController extends Controller
{
    public function generateToken(Request $request, Mqtt $mqtt)
    {
        $user = $request->user();

        // 6-character uppercase token, also used as the device's MQTT client id
        do {
            $token = strtoupper(Str::random(6));
        } while (Device::where('device_token', $token)->exists());

        $device = Device::create([
            'user_id' => $user->id,
            'device_token' => $token,
            'name' => 'New RespiroSync ESP32',
            'status' => 'pending',
        ]);

        // Retained, so the ESP32 gets its owner's thresholds on its very first connect.
        try {
            $mqtt->publishConfig($device, HardwareConfig::forUser($user));
        } catch (\Throwable $e) {
            Log::error('Failed to publish initial config', ['device_id' => $device->id, 'error' => $e->getMessage()]);
        }

        return response()->json([
            'message' => 'Token generated successfully',
            'token' => $token,
            'device' => $device,
        ]);
    }

    public function getDevices(Request $request)
    {
        $devices = $request->user()->devices()->orderBy('created_at', 'desc')->get();

        return response()->json(['devices' => $devices]);
    }

    public function deleteDevice(Request $request, Mqtt $mqtt, $id)
    {
        $device = $request->user()->devices()->findOrFail($id);

        try {
            $mqtt->sendCommand($device, 'factory_reset');
            $mqtt->clearConfig($device);
        } catch (\Throwable $e) {
            Log::error('Failed to publish factory reset: ' . $e->getMessage());
        }

        $device->delete();

        return response()->json(['message' => 'Device removed successfully']);
    }
}
