<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An ESP32 firmware build that owners can install from the dashboard (cloud OTA).
 * Published on the server with `php artisan firmware:publish`; the file is in storage/app/firmware.
 */
class FirmwareRelease extends Model
{
    /** The app partition of the "Minimal SPIFFS (1.9MB APP with OTA)" scheme: 0x1E0000 bytes. */
    public const MAX_SIZE = 1966080;

    /** Marker the firmware embeds with its version, read from the file at publish time. */
    public const VERSION_TAG = 'RespiroSync-firmware:';

    protected $fillable = ['version', 'filename', 'size', 'image_sha256', 'notes'];

    protected $casts = ['size' => 'integer'];

    public static function directory(): string
    {
        return storage_path('app/firmware');
    }

    public function path(): string
    {
        return self::directory() . DIRECTORY_SEPARATOR . $this->filename;
    }

    /** The newest release by version number, or null. */
    public static function latest(): ?self
    {
        return self::all()->sort(fn (self $a, self $b) => version_compare($b->version, $a->version))->first();
    }

    /** True when $version is newer than $current (a device that reports nothing counts as oldest). */
    public static function isNewer(string $version, ?string $current): bool
    {
        return $current === null || version_compare($version, $current, '>');
    }
}
