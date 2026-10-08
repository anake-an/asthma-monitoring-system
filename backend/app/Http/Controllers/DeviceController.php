<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Models\Device;

class DeviceController extends Controller
{
    public function generateToken(Request $request)
    {
        $user = $request->user();

        // Generate a 6-character uppercase token
        $token = strtoupper(Str::random(6));

        // Ensure token is unique
        while (Device::where('device_token', $token)->exists()) {
            $token = strtoupper(Str::random(6));
        }

        // Create a pending device
        $device = Device::create([
            'user_id' => $user->id,
            'device_token' => $token,
            'name' => 'New RespiroSync ESP32',
            'status' => 'pending'
        ]);

        return response()->json([
            'message' => 'Token generated successfully',
            'token' => $token,
            'device' => $device
        ]);
    }

    public function getDevices(Request $request)
    {
        $devices = $request->user()->devices()->orderBy('created_at', 'desc')->get();
        return response()->json(['devices' => $devices]);
    }

    public function deleteDevice(Request $request, $id)
    {
        $device = $request->user()->devices()->findOrFail($id);
        $token = $device->device_token;
        
        try {
            $mqtt = new \PhpMqtt\Client\MqttClient(env('MQTT_HOST', 'mqtt'), env('MQTT_PORT', 1883), 'laravel_publisher_' . uniqid());
            $settings = (new \PhpMqtt\Client\ConnectionSettings())->setUseTls(false);
            $mqtt->connect($settings, true);
            $mqtt->publish("respirosync/commands/{$token}", json_encode(['command' => 'factory_reset']), 0);
            $mqtt->disconnect();
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to publish factory reset: " . $e->getMessage());
        }

        $device->delete();

        return response()->json(['message' => 'Device removed successfully']);
    }
}
