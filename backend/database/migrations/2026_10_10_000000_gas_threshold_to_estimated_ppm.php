<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The firmware now reports the MQ-135 as an estimated CO2-equivalent ppm instead of the raw
 * ADC value (0-4095). Limits saved on the old scale mean nothing on the new one (300 would sit
 * below fresh air at ~420 ppm and alarm constantly), so every gas limit is reset to the new
 * default of 1000 ppm. Earlier telemetry rows keep their raw values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->float('mq135_threshold')->default(1000.0)->change();
        });
        DB::table('hardware_configs')->update(['mq135_threshold' => 1000.0]);
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->float('mq135_threshold')->default(300.0)->change();
        });
        DB::table('hardware_configs')->update(['mq135_threshold' => 300.0]);
    }
};
