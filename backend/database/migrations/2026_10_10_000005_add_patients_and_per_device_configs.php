<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Multi-patient phase 2 (DESIGN_MULTI_PATIENT.md sections 2, 4 and 6).
 *
 *  - patients: the child being monitored (display name, optional birth year; nothing else, PDPA)
 *  - patient_user: who may see a patient, with a role (owner now; caregiver/viewer in phase 3)
 *  - devices.patient_id: the room's child (NULL = shared room: shown, never attributed);
 *    devices.last_seen_at: online/offline is computed from it
 *  - hardware_configs: one row per device (device_id) instead of one per account
 *  - inhaler_logs.patient_id: a dose belongs to the child; user_id stays as "logged by"
 *  - limit_changes.device_id: which room's limit changed
 *
 * Backfill: each account gets a patient "My child" that owns its devices and doses, and every
 * device gets a copy of the account's limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->unsignedSmallInteger('birth_year')->nullable();
            $table->timestamps();
        });

        Schema::create('patient_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16)->default('owner'); // owner | caregiver | viewer
            $table->timestamps();
            $table->unique(['patient_id', 'user_id']);
        });

        Schema::table('devices', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
            $table->timestamp('last_seen_at')->nullable();
        });

        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->foreignId('patient_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('limit_changes', function (Blueprint $table) {
            $table->foreignId('device_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });

        // One config per device. The unique index on user_id goes; on MySQL the user_id foreign
        // key relies on it, so give the key its own plain index first.
        if (!Schema::hasIndex('hardware_configs', 'hardware_configs_user_id_foreign')) {
            Schema::table('hardware_configs', function (Blueprint $table) {
                $table->index('user_id', 'hardware_configs_user_id_foreign');
            });
        }
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropUnique(['user_id']);
            $table->foreignId('device_id')->nullable()->after('user_id')->unique()->constrained()->cascadeOnDelete();
        });

        $this->backfill();
    }

    /** Idempotent: only touches rows that are not linked yet. Public so a test can run it. */
    public function backfill(): void
    {
        $now = now();

        foreach (DB::table('users')->orderBy('id')->pluck('id') as $userId) {
            $patientId = DB::table('patient_user')->where('user_id', $userId)->where('role', 'owner')->orderBy('patient_id')->value('patient_id');
            if (!$patientId) {
                $patientId = DB::table('patients')->insertGetId(['name' => 'My child', 'created_at' => $now, 'updated_at' => $now]);
                DB::table('patient_user')->insert(['patient_id' => $patientId, 'user_id' => $userId, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
            }

            DB::table('devices')->where('user_id', $userId)->whereNull('patient_id')->update(['patient_id' => $patientId]);
            DB::table('inhaler_logs')->where('user_id', $userId)->whereNull('patient_id')->update(['patient_id' => $patientId]);

            $deviceIds = DB::table('devices')->where('user_id', $userId)->orderBy('id')->pluck('id');

            // The account's limits become the first device's; every other device gets a copy.
            $account = DB::table('hardware_configs')->where('user_id', $userId)->whereNull('device_id')->orderBy('id')->first();
            if ($account) {
                $copy = (array) $account;
                unset($copy['id'], $copy['device_id']);
                foreach ($deviceIds as $deviceId) {
                    if (DB::table('hardware_configs')->where('device_id', $deviceId)->exists()) {
                        continue;
                    }
                    if ($account) {
                        DB::table('hardware_configs')->where('id', $account->id)->update(['device_id' => $deviceId]);
                        $account = null;
                    } else {
                        DB::table('hardware_configs')->insert($copy + ['device_id' => $deviceId]);
                    }
                }
            }

            // Limit changes so far were per account: they belong to its only device, if it has one.
            if ($deviceIds->count() === 1) {
                DB::table('limit_changes')->where('user_id', $userId)->whereNull('device_id')->update(['device_id' => $deviceIds->first()]);
            }
        }

        // Account rows without a device are never read again (a new device starts from defaults).
        DB::table('hardware_configs')->whereNull('device_id')->delete();
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropForeign(['device_id']);
            $table->dropUnique(['device_id']);
            $table->dropColumn('device_id');
        });
        // Back to one config per account: keep each account's oldest row.
        $keep = DB::table('hardware_configs')->selectRaw('MIN(id) as id')->groupBy('user_id')->pluck('id');
        DB::table('hardware_configs')->whereNotIn('id', $keep)->delete();
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->unique('user_id');
        });

        Schema::table('limit_changes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('device_id');
        });
        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
        });
        Schema::table('devices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('patient_id');
            $table->dropColumn('last_seen_at');
        });

        Schema::dropIfExists('patient_user');
        Schema::dropIfExists('patients');
    }
};
