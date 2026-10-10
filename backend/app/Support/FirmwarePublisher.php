<?php

namespace App\Support;

use App\Models\FirmwareRelease;
use Illuminate\Support\Facades\File;

/**
 * Publishes an ESP32 app image for cloud updates: checks it (FirmwareImage), stores it in
 * storage/app/firmware as esp32-<version>.bin and records the release. Used by
 * `php artisan firmware:publish` and by the CI upload (FirmwareController::upload).
 */
class FirmwarePublisher
{
    /**
     * @return array{release: FirmwareRelease, created: bool} created=false when that version was
     *         already published (nothing changes: publishing is safe to repeat)
     * @throws \InvalidArgumentException when the file is not a usable firmware image
     */
    public static function publish(string $bytes, ?string $notes = null): array
    {
        $image = FirmwareImage::inspect($bytes);
        if ($existing = FirmwareRelease::where('version', $image['version'])->first()) {
            return ['release' => $existing, 'created' => false];
        }

        File::ensureDirectoryExists(FirmwareRelease::directory());
        $filename = "esp32-{$image['version']}.bin";
        File::put(FirmwareRelease::directory() . DIRECTORY_SEPARATOR . $filename, $bytes);

        $release = FirmwareRelease::create([
            'version' => $image['version'],
            'build' => $image['build'],
            'filename' => $filename,
            'size' => $image['size'],
            'image_sha256' => $image['image_sha256'],
            'notes' => self::notes($notes),
        ]);

        return ['release' => $release, 'created' => true];
    }

    /**
     * "What's new" for owners, one point per line. CI sends the lines of WHATS_NEW.txt joined by
     * "|" (a header cannot hold line breaks); stored with line breaks.
     */
    private static function notes(?string $notes): ?string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/[|\r\n]+/', (string) $notes))));

        return $lines ? mb_substr(implode("\n", $lines), 0, 500) : null;
    }
}
