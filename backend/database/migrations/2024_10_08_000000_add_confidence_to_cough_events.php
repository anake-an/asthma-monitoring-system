<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cough_events', function (Blueprint $table) {
            $table->float('confidence')->default(0.0)->after('severity');
        });
    }

    public function down(): void
    {
        Schema::table('cough_events', function (Blueprint $table) {
            $table->dropColumn('confidence');
        });
    }
};
