<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Firmware versions in the "3.1.0 Build 261010" form (version you choose + build stamp CI adds).
 * The first cloud-update builds called themselves 6.2.0 / 6.2.1 (the website's numbering); they
 * are firmware 3.0.0 / 3.0.1 in the firmware's own history (hardware/FIRMWARE_HISTORY.md).
 */
return new class extends Migration
{
    private const LEGACY = ['6.2.0' => ['3.0.0', '261010.5'], '6.2.1' => ['3.0.1', '261010.6']];

    public function up(): void
    {
        Schema::table('firmware_releases', function (Blueprint $table) {
            $table->string('build', 32)->nullable()->after('version');
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->string('firmware_build', 32)->nullable()->after('firmware_version');
        });

        foreach (self::LEGACY as $old => [$version, $build]) {
            DB::table('firmware_releases')->where('version', $old)->update(['version' => $version, 'build' => $build]);
            DB::table('devices')->where('firmware_version', $old)->update(['firmware_version' => $version, 'firmware_build' => $build]);
            DB::table('devices')->where('ota_target_version', $old)->update(['ota_target_version' => $version]);
        }
    }

    public function down(): void
    {
        foreach (self::LEGACY as $old => [$version]) {
            DB::table('firmware_releases')->where('version', $version)->update(['version' => $old]);
            DB::table('devices')->where('firmware_version', $version)->update(['firmware_version' => $old]);
            DB::table('devices')->where('ota_target_version', $version)->update(['ota_target_version' => $old]);
        }
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('firmware_build');
        });
        Schema::table('firmware_releases', function (Blueprint $table) {
            $table->dropColumn('build');
        });
    }
};
