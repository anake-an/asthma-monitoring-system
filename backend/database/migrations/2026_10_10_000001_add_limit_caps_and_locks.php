<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Each alert limit now has three parts:
 *   <name>_threshold  the effective limit the device uses (unchanged column)
 *   <name>_cap        the user's own value: the AI may tighten below it, never above it
 *   <name>_locked     the AI never changes this limit; effective = cap
 * Existing rows start with cap = their current limit and no locks.
 */
return new class extends Migration
{
    private const LIMITS = ['pm25', 'temperature', 'humidity', 'mq135'];

    public function up(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            foreach (self::LIMITS as $name) {
                $table->float("{$name}_cap")->nullable()->after("{$name}_threshold");
                $table->boolean("{$name}_locked")->default(false)->after("{$name}_cap");
            }
        });

        foreach (self::LIMITS as $name) {
            DB::table('hardware_configs')->update(["{$name}_cap" => DB::raw("{$name}_threshold")]);
        }
    }

    public function down(): void
    {
        Schema::table('hardware_configs', function (Blueprint $table) {
            foreach (self::LIMITS as $name) {
                $table->dropColumn(["{$name}_cap", "{$name}_locked"]);
            }
        });
    }
};
