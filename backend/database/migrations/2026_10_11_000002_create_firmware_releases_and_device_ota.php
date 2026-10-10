<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cloud firmware updates (OTA). firmware_releases: ESP32 builds published on the server with
 * `php artisan firmware:publish` (the .bin files live in storage/app/firmware, not in git).
 * devices: the firmware each device reports, and the state of its last update.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('firmware_releases', function (Blueprint $table) {
            $table->id();
            $table->string('version', 32)->unique();   // read from the file ("RespiroSync-firmware:x.y.z")
            $table->string('filename');                // in storage/app/firmware
            $table->unsignedInteger('size');
            $table->char('image_sha256', 64);          // the image digest the ESP32 checks after writing it
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->string('firmware_version', 32)->nullable()->after('status');
            $table->string('ota_status', 16)->nullable()->after('firmware_version'); // updating | updated | failed
            $table->string('ota_target_version', 32)->nullable()->after('ota_status');
            $table->string('ota_error', 160)->nullable()->after('ota_target_version');
            $table->timestamp('ota_started_at')->nullable()->after('ota_error');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['firmware_version', 'ota_status', 'ota_target_version', 'ota_error', 'ota_started_at']);
        });
        Schema::dropIfExists('firmware_releases');
    }
};
