<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cloud updates for the Edge AI module (the Raspberry Pi Pico), sent on by the ESP32.
 * firmware_releases.target: "esp32" (device firmware) or "pico" (Edge AI); a version is unique
 * per target. devices: the Edge AI version the ESP32 reports, and which part an update is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('firmware_releases', function (Blueprint $table) {
            $table->string('target', 16)->default('esp32')->after('id');
            $table->dropUnique(['version']);
            $table->unique(['target', 'version']);
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->string('edge_ai_version', 32)->nullable()->after('firmware_build');
            $table->string('edge_ai_build', 32)->nullable()->after('edge_ai_version');
            $table->string('ota_target', 16)->nullable()->after('ota_status'); // esp32 | pico
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn(['edge_ai_version', 'edge_ai_build', 'ota_target']);
        });
        Schema::table('firmware_releases', function (Blueprint $table) {
            $table->dropUnique(['target', 'version']);
            $table->unique('version');
            $table->dropColumn('target');
        });
    }
};
