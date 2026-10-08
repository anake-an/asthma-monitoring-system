<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->string('type')->default('rescue')->after('is_manual');
        });
    }

    public function down()
    {
        Schema::table('inhaler_logs', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
