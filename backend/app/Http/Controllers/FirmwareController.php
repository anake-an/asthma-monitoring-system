<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Support\FirmwarePublisher;
use App\Support\Mqtt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Cloud firmware updates (OTA). An owner starts the update of one room's device to the newest
 * published release; the device gets an MQTT "ota" command with a one-time HTTPS link (valid
 * OTA_LINK_MINUTES) and the image digest, downloads the file from this server, checks it,
 * installs it and restarts. Its next "hello" says whether it worked (DeviceMessageHandler).
 */
class FirmwareController extends Controller
{
    public const OTA_LINK_MINUTES = 10;

    /** POST /api/devices/{id}/firmware-update (owners: DevicePolicy "configure"). */
    public function update(Request $request, $id)
    {
        $request->merge(['device_id' => $id]);
        $device = $this->device($request, 'configure');

        $release = FirmwareRelease::latest();
        if (!$release || !FirmwareRelease::isNewer($release->version, $device->firmware_version)) {
            return response()->json(['message' => 'This device already has the newest firmware.'], 422);
        }
        if ($device->status !== 'online') {
            return response()->json(['message' => 'The device is offline. Turn it on and try again.'], 422);
        }
        if ($device->otaState()['status'] === 'updating') {
            return response()->json(['message' => 'An update is already running on this device.'], 409);
        }

        $link = URL::temporarySignedRoute('firmware.download', now()->addMinutes(self::OTA_LINK_MINUTES),
            ['release' => $release->id, 'device' => $device->id], absolute: false);
        $from = $device->firmware_version;
        $device->forceFill([
            'ota_status' => 'updating',
            'ota_target_version' => $release->version,
            'ota_error' => null,
            'ota_started_at' => now(),
        ])->save();

        app(Mqtt::class)->sendCommand($device, 'ota', [
            'url' => rtrim(config('services.frontend.url'), '/') . $link,
            'version' => $release->version,
            'size' => $release->size,
            'sha256' => $release->image_sha256,
        ]);
        AuditLog::record($request->user(), 'device.firmware', $device->patient_id, $device->id,
            ['room' => $device->name, 'from' => $from ?? 'unknown', 'to' => $release->version]);

        return response()->json(['message' => "Updating {$device->name} to {$release->version}", 'device' => $device->fresh()]);
    }

    /**
     * POST /api/firmware/upload: a firmware build from CI (GitHub Actions), as the raw .ino.bin body
     * with "Authorization: Bearer <FIRMWARE_UPLOAD_TOKEN>" and an optional X-Firmware-Notes header.
     * Off (404) while no token is set in backend/.env. Uploading a version that is already
     * published changes nothing (200), so CI can send every build.
     */
    public function upload(Request $request)
    {
        $token = (string) config('services.firmware.upload_token');
        if ($token === '') {
            abort(404);
        }
        if (!hash_equals($token, (string) $request->bearerToken())) {
            return response()->json(['message' => 'Invalid upload token.'], 401);
        }

        try {
            ['release' => $release, 'created' => $created] = FirmwarePublisher::publish(
                $request->getContent(), $request->header('X-Firmware-Notes'));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => $created ? "Published firmware {$release->version}" : "Firmware {$release->version} was already published",
            'version' => $release->version,
            'created' => $created,
        ], $created ? 201 : 200);
    }

    /**
     * GET /api/firmware/{release}/download?device=…&expires=…&signature=… (no login: the device
     * has none). Only through a valid, unexpired link made for that device and that release.
     */
    public function download(Request $request, FirmwareRelease $release)
    {
        $device = Device::find($request->query('device'));
        if (!$device || $device->ota_target_version !== $release->version || !is_file($release->path())) {
            abort(404);
        }

        return response()->file($release->path(), [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }
}
