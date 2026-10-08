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
        Schema::table('cough_events', function (Blueprint $table) {
            $table->boolean('is_verified')->nullable()->comment('null=pending, true=confirmed, false=false alarm');
            $table->boolean('inhaler_used')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cough_events', function (Blueprint $table) {
            $table->dropColumn(['is_verified', 'inhaler_used']);
        });
    }
};
