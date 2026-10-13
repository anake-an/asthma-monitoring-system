<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Possible coughs: sounds the Edge AI module was almost sure of (a little under the room's cough
 * threshold). Shown in the cough history to be reviewed; they count as a cough only once a caregiver
 * confirms one, and a confirmed one makes the room more sensitive (App\Services\CoughSensitivity).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cough_events', function (Blueprint $table) {
            $table->boolean('is_possible')->default(false)->after('confidence');
        });
    }

    public function down(): void
    {
        Schema::table('cough_events', function (Blueprint $table) {
            $table->dropColumn('is_possible');
        });
    }
};
