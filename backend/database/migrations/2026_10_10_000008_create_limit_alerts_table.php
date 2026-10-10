<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per "reading over its limit for 5 minutes" alert (email + push), per room and reading.
 * Used for the repeat interval (at most one per room and reading per hour) and as history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('reading', 16);          // pm25 | temperature | humidity | mq135
            $table->float('value');                 // the reading when the alert was sent
            $table->float('limit_value');           // the room's limit at that moment
            $table->timestamp('created_at');        // app clock
            $table->index(['device_id', 'reading', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_alerts');
    }
};
