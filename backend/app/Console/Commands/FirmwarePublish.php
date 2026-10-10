<?php

namespace App\Console\Commands;

use App\Models\FirmwareRelease;
use App\Support\FirmwarePublisher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Publishes an ESP32 firmware build for cloud updates. Owners then see "Update to x.y.z" for their
 * rooms in the dashboard. The version comes from the file itself.
 *
 *   php artisan firmware:publish storage/app/firmware/esp32_firmware.ino.bin --notes="New LCD pages"
 *   php artisan firmware:publish --list
 *   php artisan firmware:publish --remove=6.2.0
 */
class FirmwarePublish extends Command
{
    protected $signature = 'firmware:publish {file? : The .ino.bin, relative to the backend folder}
        {--notes= : What changed (shown to owners)}
        {--list : Show the published versions}
        {--remove= : Unpublish a version and delete its file}
        {--target=esp32 : With --remove: esp32 (device firmware) or pico (Edge AI)}';

    protected $description = 'Publish an ESP32 firmware build for cloud updates (or list / remove them)';

    public function handle()
    {
        if ($this->option('list')) {
            $rows = FirmwareRelease::all()->sort(fn ($a, $b) => version_compare($b->version, $a->version))
                ->map(fn ($r) => [FirmwareRelease::TARGETS[$r->target] ?? $r->target, FirmwareRelease::label($r->version, $r->build), number_format($r->size), $r->created_at?->toDateTimeString(), $r->notes]);
            $this->table(['For', 'Version', 'Bytes', 'Published', 'Notes'], $rows->all());

            return self::SUCCESS;
        }

        if ($version = $this->option('remove')) {
            $release = FirmwareRelease::where('target', $this->option('target'))->where('version', $version)->first();
            if (!$release) {
                $this->error("No published version {$version}.");

                return self::FAILURE;
            }
            File::delete($release->path());
            $release->delete();
            $this->info("Removed {$version}.");

            return self::SUCCESS;
        }

        $file = $this->argument('file');
        $path = $file && !str_starts_with($file, '/') ? base_path($file) : $file;
        if (!$path || !is_file($path)) {
            $this->error('Give the path of the .ino.bin file, e.g. storage/app/firmware/esp32_firmware.ino.bin');

            return self::FAILURE;
        }

        try {
            ['release' => $release, 'created' => $created] = FirmwarePublisher::publish(file_get_contents($path), $this->option('notes'));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        if (!$created) {
            $this->error("Version {$release->version} is already published. Raise FIRMWARE_VERSION in the firmware, or --remove={$release->version} first.");

            return self::FAILURE;
        }
        if (realpath($path) !== realpath($release->path())
            && str_starts_with((string) realpath($path), (string) realpath(FirmwareRelease::directory()))) {
            File::delete($path); // it was dropped into the firmware folder: keep only the published copy
        }
        $this->info("Published firmware {$release->version} ({$release->size} bytes). Owners can now update their rooms in Account Settings > Rooms.");

        return self::SUCCESS;
    }
}
