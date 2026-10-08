<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->float('mq135_threshold')->default(300.0)->after('humidity_threshold');
            $table->boolean('ai_optimization_enabled')->default(true)->after('is_buzzer_muted');
        });
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn('mq135_threshold');
            $table->dropColumn('ai_optimization_enabled');
        });
    }
};
