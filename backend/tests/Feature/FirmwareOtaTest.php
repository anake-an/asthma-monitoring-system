<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\FirmwareRelease;
use App\Models\Patient;
use App\Models\User;
use App\Services\DeviceMessageHandler;
use App\Support\FirmwareImage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Cloud firmware updates: publishing a build, an owner starting an update, the one-time download
 * link, and the device's answers (hello / ota report).
 */
class FirmwareOtaTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Patient $child;
    private Device $bedroom;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        // Firmware files go to a throwaway storage folder, never the real one.
        $this->storage = sys_get_temp_dir() . '/respirosync-ota-' . uniqid();
        File::ensureDirectoryExists($this->storage . '/app');
        $this->app->useStoragePath($this->storage);

        $this->owner = User::factory()->create();
        $this->child = $this->owner->defaultPatient();
        $this->bedroom = Device::create(['user_id' => $this->owner->id, 'patient_id' => $this->child->id, 'device_token' => 'BED001', 'name' => 'Bedroom']);
        $this->bedroom->forceFill(['last_seen_at' => now(), 'firmware_version' => '6.2.0'])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->storage);
        parent::tearDown();
    }

    /** A minimal ESP32 app image: magic byte, hash_appended flag, version marker, 32-byte digest. */
    private function image(string $version, bool $hashAppended = true, string $magic = "\xE9"): string
    {
        $header = $magic . str_repeat("\0", 22) . ($hashAppended ? "\x01" : "\0");

        return $header . str_repeat("\x55", 100) . FirmwareRelease::VERSION_TAG . $version . "\0" . str_repeat("\x55", 100) . hash('sha256', $version, true);
    }

    private function publish(string $version): FirmwareRelease
    {
        File::ensureDirectoryExists(FirmwareRelease::directory());
        File::put(FirmwareRelease::directory() . '/esp32_firmware.ino.bin', $this->image($version));
        $this->artisan('firmware:publish', ['file' => FirmwareRelease::directory() . '/esp32_firmware.ino.bin'])->assertSuccessful();

        return FirmwareRelease::where('version', $version)->firstOrFail();
    }

    private function hello(array $data): void
    {
        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/events', json_encode(['event' => 'hello'] + $data));
    }

    public function test_an_image_is_checked_and_its_version_and_digest_read(): void
    {
        $info = FirmwareImage::inspect($this->image('6.3.0'));
        $this->assertSame('6.3.0', $info['version']);
        $this->assertSame(hash('sha256', '6.3.0'), $info['image_sha256']);

        foreach ([
            'merged image (bootloader area first)' => $this->image('6.3.0', magic: "\xFF"),
            'no appended hash' => $this->image('6.3.0', hashAppended: false),
            'no version marker' => "\xE9" . str_repeat("\0", 22) . "\x01" . str_repeat("\x55", 200),
            'too big' => $this->image('6.3.0') . str_repeat("\0", FirmwareRelease::MAX_SIZE),
        ] as $why => $bytes) {
            try {
                FirmwareImage::inspect($bytes);
                $this->fail("accepted: {$why}");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_publishing_stores_the_file_and_refuses_the_same_version_twice(): void
    {
        $release = $this->publish('6.3.0');

        $this->assertFileExists($release->path());
        $this->assertFileDoesNotExist(FirmwareRelease::directory() . '/esp32_firmware.ino.bin');

        File::put(FirmwareRelease::directory() . '/again.bin', $this->image('6.3.0'));
        $this->artisan('firmware:publish', ['file' => FirmwareRelease::directory() . '/again.bin'])->assertFailed();
    }

    public function test_the_owner_starts_an_update_and_the_device_gets_a_one_time_link(): void
    {
        $release = $this->publish('6.3.0');
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/devices')->assertJsonPath('latest_firmware.version', '6.3.0');
        $this->postJson("/api/devices/{$this->bedroom->id}/firmware-update")->assertOk();

        $this->assertSame('updating', $this->bedroom->fresh()->ota_status);
        [$topic, $payload] = collect($this->published)->last();
        $this->assertSame('respirosync/devices/BED001/commands', $topic);
        $command = json_decode($payload, true);
        $this->assertSame('ota', $command['command']);
        $this->assertSame('6.3.0', $command['version']);
        $this->assertSame($release->image_sha256, $command['sha256']);
        $this->assertStringContainsString("/api/firmware/{$release->id}/download?device={$this->bedroom->id}", $command['url']);

        // The link downloads the file; a changed link does not.
        $relative = substr($command['url'], strpos($command['url'], '/api/'));
        $this->get($relative)->assertOk();
        $this->get($relative . 'x')->assertForbidden();

        // A second update while this one runs is refused.
        $this->postJson("/api/devices/{$this->bedroom->id}/firmware-update")->assertStatus(409);
    }

    public function test_updates_need_a_newer_release_an_online_device_and_an_owner(): void
    {
        $this->publish('6.2.0');
        Sanctum::actingAs($this->owner);
        $this->postJson("/api/devices/{$this->bedroom->id}/firmware-update")->assertStatus(422); // already has it

        $this->publish('6.3.0');
        $this->bedroom->forceFill(['last_seen_at' => now()->subMinutes(5)])->save();
        $this->postJson("/api/devices/{$this->bedroom->id}/firmware-update")->assertStatus(422); // offline

        $this->bedroom->forceFill(['last_seen_at' => now()])->save();
        $caregiver = User::factory()->create();
        $this->child->users()->attach($caregiver->id, ['role' => Patient::CAREGIVER]);
        Sanctum::actingAs($caregiver);
        $this->postJson("/api/devices/{$this->bedroom->id}/firmware-update")->assertForbidden();
    }

    public function test_a_link_only_works_for_the_device_being_updated(): void
    {
        $release = $this->publish('6.3.0');
        $link = \Illuminate\Support\Facades\URL::temporarySignedRoute('firmware.download', now()->addMinutes(10),
            ['release' => $release->id, 'device' => $this->bedroom->id], absolute: false);

        $this->get($link)->assertNotFound(); // validly signed, but no update was started for it
    }

    public function test_the_devices_hello_tells_whether_the_update_worked(): void
    {
        $this->bedroom->forceFill(['ota_status' => 'updating', 'ota_target_version' => '6.3.0', 'ota_started_at' => now()])->save();

        $this->hello(['firmware' => '6.2.0']); // a reconnect while downloading: still running
        $this->assertSame('updating', $this->bedroom->fresh()->ota_status);

        $this->hello(['firmware' => '6.3.0', 'boot' => true]);
        $this->assertSame('updated', $this->bedroom->fresh()->ota_status);
        $this->assertSame('6.3.0', $this->bedroom->fresh()->firmware_version);
    }

    public function test_a_restart_with_the_old_version_or_a_report_means_it_failed(): void
    {
        $this->bedroom->forceFill(['ota_status' => 'updating', 'ota_target_version' => '6.3.0', 'ota_started_at' => now()])->save();
        $this->hello(['firmware' => '6.2.0', 'boot' => true]); // rolled back
        $this->assertSame('failed', $this->bedroom->fresh()->ota_status);

        $this->bedroom->forceFill(['ota_status' => 'updating', 'ota_error' => null])->save();
        app(DeviceMessageHandler::class)->handle('respirosync/devices/BED001/events', json_encode(['event' => 'ota', 'status' => 'failed', 'error' => 'Checksum does not match']));
        $this->assertSame('Checksum does not match', $this->bedroom->fresh()->ota_error);
    }

    public function test_an_update_without_an_answer_times_out(): void
    {
        $this->bedroom->forceFill(['ota_status' => 'updating', 'ota_target_version' => '6.3.0',
            'ota_started_at' => now()->subMinutes(Device::OTA_TIMEOUT_MINUTES + 1)])->save();
        Sanctum::actingAs($this->owner);

        $this->getJson('/api/devices')->assertJsonPath('devices.0.ota.status', 'failed');
    }
}
