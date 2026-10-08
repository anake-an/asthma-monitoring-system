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
            $table->index('recorded_at');
        });

        Schema::table('cough_events', function (Blueprint $table) {
            $table->index('recorded_at');
        });

        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->index('administered_at');
            $table->index('cough_event_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropIndex(['recorded_at']);
        });

        Schema::table('cough_events', function (Blueprint $table) {
            $table->dropIndex(['recorded_at']);
        });

        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->dropIndex(['administered_at']);
            $table->dropIndex(['cough_event_id']);
        });
    }
};
