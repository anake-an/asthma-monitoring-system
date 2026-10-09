<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user data ownership + honest sensor/AI values.
 *
 *  - inhaler_logs.user_id: inhaler doses belong to an account (they were global before)
 *  - hardware_configs.user_id is now actually used, one profile per account
 *  - cough_events.confidence nullable: "device did not report one" is not 0.0
 *  - telemetry temperature/humidity nullable: a failed DHT22 read is stored as NULL, not 0
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('inhaler_logs', 'user_id')) {
            Schema::table('inhaler_logs', function (Blueprint $table) {
                $table->foreignId('user_id')->nullable()->after('id')->constrained('users')->cascadeOnDelete();
            });
        }

        Schema::table('cough_events', function (Blueprint $table) {
            $table->float('confidence')->nullable()->default(null)->change();
        });

        Schema::table('telemetry_logs', function (Blueprint $table) {
            $table->float('temperature')->nullable()->change();
            $table->float('humidity')->nullable()->change();
        });

        // Old code stored 0.0 when the Pico sent no confidence; that meant "unknown".
        DB::table('cough_events')->where('confidence', 0)->update(['confidence' => null]);

        // Backfill inhaler doses that were linked to a cough event.
        $linked = DB::table('inhaler_logs')
            ->join('cough_events', 'cough_events.id', '=', 'inhaler_logs.cough_event_id')
            ->join('devices', 'devices.id', '=', 'cough_events.device_id')
            ->whereNull('inhaler_logs.user_id')
            ->select('inhaler_logs.id', 'devices.user_id', 'devices.id as device_id')
            ->get();
        foreach ($linked as $row) {
            DB::table('inhaler_logs')->where('id', $row->id)->update(['user_id' => $row->user_id, 'device_id' => $row->device_id]);
        }

        // Single-account installs (the reference NAS deployment): everything unowned belongs to that account.
        if (DB::table('users')->count() === 1) {
            $userId = DB::table('users')->value('id');
            DB::table('inhaler_logs')->whereNull('user_id')->update(['user_id' => $userId]);

            if (!DB::table('hardware_configs')->where('user_id', $userId)->exists()) {
                $legacy = DB::table('hardware_configs')->whereNull('user_id')->orderBy('id')->value('id');
                if ($legacy) {
                    DB::table('hardware_configs')->where('id', $legacy)->update(['user_id' => $userId]);
                }
            }
        }

        // Unowned global config rows are never read any more.
        DB::table('hardware_configs')->whereNull('user_id')->delete();

        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->unique('user_id');
        });
    }

    public function down(): void
    {
        // MySQL dropped the foreign key's own index when the unique index took over, so
        // the foreign key now depends on the unique one. Give it a plain index first.
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->index('user_id', 'hardware_configs_user_id_foreign');
        });
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
        });

        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('user_id');
        });
    }
};
