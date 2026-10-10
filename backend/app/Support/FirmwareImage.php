<?php

namespace App\Support;

use App\Models\FirmwareRelease;
use InvalidArgumentException;

/**
 * Checks an ESP32 app image (the ".ino.bin" from Arduino's "Export Compiled Binary") before it is
 * published, and reads what the update needs from it:
 *  - it starts with the ESP image magic byte 0xE9 (the ".merged.bin" starts with the bootloader
 *    area and is refused), fits the OTA app partition, and has its SHA-256 appended;
 *  - the version, from the "RespiroSync-firmware:x.y.z" marker the firmware embeds;
 *  - the image digest (the appended 32 bytes): after writing the image, the ESP32 reads the same
 *    value back from flash (esp_partition_get_sha256) and refuses a mismatch.
 */
class FirmwareImage
{
    private const MAGIC = "\xE9";

    private const HASH_APPENDED_OFFSET = 23; // esp_image_header_t.hash_appended

    /** "<version>( build <build>)" after a marker. */
    private const VERSION_PATTERN = '(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)(?: build ([0-9.]+|dev))?';

    /**
     * An ESP32 app image (target "esp32") or an Edge AI build for the Pico (target "pico").
     *
     * @return array{target: string, version: string, build: ?string, image_sha256: string, size: int}
     */
    public static function inspect(string $bytes): array
    {
        $size = strlen($bytes);
        if (preg_match('/' . preg_quote(FirmwareRelease::EDGE_AI_TAG, '/') . self::VERSION_PATTERN . '/', $bytes, $edge)) {
            return self::inspectEdgeAi($bytes, $size, $edge);
        }
        if ($size < 64 || $bytes[0] !== self::MAGIC) {
            throw new InvalidArgumentException('Not an ESP32 app image. Use the ".ino.bin" file, not ".merged.bin" or ".bootloader.bin".');
        }
        if ($size > FirmwareRelease::MAX_SIZE) {
            throw new InvalidArgumentException("The image is {$size} bytes; the OTA app partition holds " . FirmwareRelease::MAX_SIZE
                . '. Build with the "Minimal SPIFFS (1.9MB APP with OTA)" partition scheme.');
        }
        if (ord($bytes[self::HASH_APPENDED_OFFSET]) !== 1) {
            throw new InvalidArgumentException('The image has no appended SHA-256 (hash_appended is off).');
        }
        // "RespiroSync-firmware:3.1.0 build 261010.2" (the build is "dev" in a build made outside CI)
        $pattern = '/' . preg_quote(FirmwareRelease::VERSION_TAG, '/') . self::VERSION_PATTERN . '/';
        if (!preg_match($pattern, $bytes, $m)) {
            throw new InvalidArgumentException('No RespiroSync version found in the file (is it the RespiroSync ESP32 firmware 3.0.0+, or the Edge AI build 2.0.0+?).');
        }
        [$version, $build] = FirmwareRelease::normalize($m[1], ($m[2] ?? '') !== '' ? $m[2] : null);

        return ['target' => 'esp32', 'version' => $version, 'build' => $build, 'image_sha256' => bin2hex(substr($bytes, -32)), 'size' => $size];
    }

    /**
     * The Pico build (".ino.bin" from arduino-pico, sent to the module as is). Its checksum is the
     * SHA-256 of the whole file, which the ESP32 computes while sending it on.
     */
    private static function inspectEdgeAi(string $bytes, int $size, array $m): array
    {
        if ($size > FirmwareRelease::EDGE_AI_MAX_SIZE) {
            throw new InvalidArgumentException("The Edge AI build is {$size} bytes; the module holds " . FirmwareRelease::EDGE_AI_MAX_SIZE
                . '. Build with Flash Size "2MB (Sketch: 1MB, FS: 1MB)".');
        }
        if (str_starts_with($bytes, "UF2\n")) {
            throw new InvalidArgumentException('That is the .uf2 file: publish the ".ino.bin" instead.');
        }

        return ['target' => 'pico', 'version' => $m[1], 'build' => ($m[2] ?? '') !== '' ? $m[2] : null,
            'image_sha256' => hash('sha256', $bytes), 'size' => $size];
    }
}
