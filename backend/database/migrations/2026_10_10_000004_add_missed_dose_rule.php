<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Missed daily dose rule (DESIGN_MULTI_PATIENT.md 5.5). While it is on, missed_dose_base holds the
 * limits without the rule ({"pm25": 31.5, ...}) and the effective limits are 15 % lower; NULL = off.
 * Per account for now; per patient once patients exist (design section 6).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->json('missed_dose_base')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn('missed_dose_base');
        });
    }
};
