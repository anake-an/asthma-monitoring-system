<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The AI may move each limit by at most 10 % per 24 h. <name>_day_start is the effective limit at
 * the start of the current 24-hour window; ai_day_started_at is when that window began.
 */
return new class extends Migration
{
    private const LIMITS = ['pm25', 'temperature', 'humidity', 'mq135'];

    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->timestamp('ai_day_started_at')->nullable();
            foreach (self::LIMITS as $name) {
                $table->float("{$name}_day_start")->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            $table->dropColumn(array_merge(['ai_day_started_at'], array_map(fn ($n) => "{$n}_day_start", self::LIMITS)));
        });
    }
};
