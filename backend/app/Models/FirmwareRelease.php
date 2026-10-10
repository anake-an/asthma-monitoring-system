<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An ESP32 firmware build that owners can install from the dashboard (cloud OTA).
 * Versions read like "3.1.0 Build 261010": a version chosen in the firmware (major.feature.fix)
 * and a build stamp CI adds (build date YYMMDD, ".2", ".3" for more builds that day).
 * Published by CI (POST /api/firmware/upload) or `php artisan firmware:publish`; the file is in
 * storage/app/firmware. History: hardware/FIRMWARE_HISTORY.md.
 */
class FirmwareRelease extends Model
{
    /** The app partition of the "Minimal SPIFFS (1.9MB APP with OTA)" scheme: 0x1E0000 bytes. */
    public const MAX_SIZE = 1966080;

    /** Marker the firmware embeds ("RespiroSync-firmware:3.1.0 build 261010"), read at publish time. */
    public const VERSION_TAG = 'RespiroSync-firmware:';

    /** The Edge AI module (Raspberry Pi Pico): its marker ("RespiroSync-edge-ai:2.0.0 build 261011") and room. */
    public const EDGE_AI_TAG = 'RespiroSync-edge-ai:';
    public const EDGE_AI_MAX_SIZE = 1040384; // the sketch half of "2MB (Sketch: 1MB, FS: 1MB)", less a margin

    /** What a release is for: the device firmware (ESP32) or the Edge AI module (Pico). */
    public const TARGETS = ['esp32' => 'Firmware', 'pico' => 'Edge AI'];

    /** Product shown to owners. */
    public const PRODUCT = 'RespiroSync Room Monitor';
    public const MODEL = 'RS-100';
    public const HARDWARE_REVISION = 'A';

    /** The first cloud-update builds used the website's numbers; their place in the firmware history. */
    public const LEGACY = ['6.2.0' => ['3.0.0', '261010.5'], '6.2.1' => ['3.0.1', '261010.6']];

    protected $fillable = ['target', 'version', 'build', 'filename', 'size', 'image_sha256', 'notes'];

    protected $casts = ['size' => 'integer'];

    public static function directory(): string
    {
        return storage_path('app/firmware');
    }

    public function path(): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . $this->filename;
    }

    /** [version, build] with the legacy 6.2.x labels mapped to their real firmware version. */
    public static function normalize(?string $version, ?string $build): array
    {
        return self::LEGACY[$version] ?? [$version, $build];
    }

    /**
     * Order of two firmware builds: by version, then by build stamp (both dotted numbers).
     * A missing or "dev" build does not decide. A device that reports nothing is the oldest.
     */
    public static function compare(?string $versionA, ?string $buildA, ?string $versionB, ?string $buildB): int
    {
        if ($versionA === null || $versionB === null) {
            return ($versionA !== null) <=> ($versionB !== null);
        }
        $byVersion = version_compare($versionA, $versionB);
        if ($byVersion !== 0 || !self::isStamp($buildA) || !self::isStamp($buildB)) {
            return $byVersion;
        }

        return version_compare($buildA, $buildB);
    }

    private static function isStamp(?string $build): bool
    {
        return $build !== null && preg_match('/^\d{6}(\.\d+)?$/', $build) === 1;
    }

    /** True when this release is newer than what a device runs. */
    public function isNewerThan(?string $version, ?string $build): bool
    {
        [$version, $build] = self::normalize($version, $build);

        return self::compare($this->version, $this->build, $version, $build) > 0;
    }

    /** The newest release for a target (esp32 or pico), or null. */
    public static function latest(string $target = 'esp32'): ?self
    {
        return self::where('target', $target)->get()
            ->sort(fn (self $a, self $b) => self::compare($b->version, $b->build, $a->version, $a->build))->first();
    }

    /** "3.1.0 Build 261010" (or just the version for a build without a stamp). */
    public static function label(?string $version, ?string $build): ?string
    {
        if ($version === null) {
            return null;
        }

        return self::isStamp($build) ? "{$version} Build {$build}" : $version;
    }
}
