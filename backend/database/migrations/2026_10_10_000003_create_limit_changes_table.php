<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * One row per change of an effective alert limit, by the AI (ai:optimize) or by the user
 * (Smart Alerts), with the reason. Shown in the Activity Log (weekly report).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('limit_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('limit_name', 16);          // pm25 | temperature | humidity | mq135
            $table->float('old_value')->nullable();
            $table->float('new_value');
            $table->string('source', 8);               // ai | user
            $table->string('reason')->nullable();
            $table->timestamp('created_at');           // written by the app clock
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('limit_changes');
    }
};
