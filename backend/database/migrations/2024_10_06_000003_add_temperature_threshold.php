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
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->float('temperature_threshold')->default(35.0)->after('pm25_threshold');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn('temperature_threshold');
        });
    }
};
