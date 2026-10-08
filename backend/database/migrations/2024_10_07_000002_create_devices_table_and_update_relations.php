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
        // 1. Create Devices Table
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('device_token')->unique(); // The Captive Portal Token
            $table->string('mac_address')->nullable()->unique(); // Set when ESP32 connects
            $table->string('name')->default('My RespiroSync Device');
            $table->string('status')->default('pending'); // pending, online, offline
            $table->timestamps();
        });

        // 2. Add device_id to Telemetry Logs
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('cascade');
        });

        // 3. Add device_id to Cough Events
        Schema::table('cough_events', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('cascade');
        });

        // 4. Add device_id to Inhaler Logs
        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->constrained('devices')->onDelete('cascade');
        });
        
        // 5. Add user_id to Hardware Configs (to allow per-user thresholds)
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropColumn('device_id');
        });

        Schema::table('cough_events', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropColumn('device_id');
        });

        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropColumn('device_id');
        });

        Schema::dropIfExists('devices');
    }
};
