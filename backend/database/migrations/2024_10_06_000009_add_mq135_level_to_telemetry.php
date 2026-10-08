<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->float('mq135_level')->nullable()->after('pm25_level')->comment('Air Quality VOC level from MQ135 sensor');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropColumn('mq135_level');
        });
    }
};
