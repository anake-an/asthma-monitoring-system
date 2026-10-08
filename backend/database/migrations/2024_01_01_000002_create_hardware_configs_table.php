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
        Schema::create('hardware_configs', function (Blueprint $table) {
            $table->id();
            $table->float('pm25_threshold')->default(35.0);
            $table->float('humidity_threshold')->default(60.0);
            $table->boolean('is_buzzer_muted')->default(false);
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();
        });

        // Insert default config
        DB::table('hardware_configs')->insert([
            'pm25_threshold' => 35.0,
            'humidity_threshold' => 60.0,
            'is_buzzer_muted' => false,
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('hardware_configs');
    }
};
