<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Telemetry retention (DESIGN_MULTI_PATIENT.md section 7, phase 5; php artisan telemetry:prune):
 *  - samples: NULL for a raw reading; for a 10-minute average, how many readings it replaced.
 *  - (device_id, recorded_at) index: every dashboard poll and the nightly prune ask for one
 *    device's readings by time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->unsignedInteger('samples')->nullable();
            $table->index(['device_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        // MySQL may have dropped the device_id foreign key's own index once the composite index
        // (which starts with device_id) could serve it; give the key a plain index back first.
        if (!Schema::hasIndex('telemetry_logs', 'telemetry_logs_device_id_foreign')) {
            Schema::table('telemetry_logs', function (Blueprint $table) {
                $table->index('device_id', 'telemetry_logs_device_id_foreign');
            });
        }
        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->dropIndex(['device_id', 'recorded_at']);
            $table->dropColumn('samples');
        });
    }
};
