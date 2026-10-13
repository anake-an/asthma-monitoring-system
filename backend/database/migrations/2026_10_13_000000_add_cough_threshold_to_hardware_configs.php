<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Cough sensitivity per room: the cough score (0-1) the Edge AI module needs before it reports a
 * cough. Starts at 0.6 (the model's own threshold) and follows the caregivers' cough reviews
 * (App\Services\CoughSensitivity). Sent to the device with the alert limits.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->float('cough_threshold')->default(0.6)->after('night_mode');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn('cough_threshold');
        });
    }
};
