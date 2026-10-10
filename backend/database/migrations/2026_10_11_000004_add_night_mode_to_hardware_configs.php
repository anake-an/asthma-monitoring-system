<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Night mode per room: the device's LCD backlight is off 21:00-07:00 (alerts still light it).
 * On by default, as before; owners can switch it off in Smart Alerts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->boolean('night_mode')->default(true)->after('is_buzzer_muted');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn('night_mode');
        });
    }
};
