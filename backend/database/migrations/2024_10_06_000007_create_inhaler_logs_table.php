<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inhaler_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('administered_at')->useCurrent();
            $table->boolean('is_manual')->default(true);
            $table->unsignedBigInteger('cough_event_id')->nullable();
            
            $table->foreign('cough_event_id')->references('id')->on('cough_events')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inhaler_logs');
    }
};
