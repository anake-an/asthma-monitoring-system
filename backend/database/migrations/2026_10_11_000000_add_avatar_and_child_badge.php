<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * An account photo (a small JPEG/PNG/WebP as a data URL, resized to 256 px in the browser, so it is
 * in the database backups and goes with the account), and a colour plus an emoji or initial per
 * child instead of a child's photo (PDPA data minimisation).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->mediumText('avatar')->nullable()->after('email');
        });
        Schema::table('patients', function (Blueprint $table) {
            $table->string('color', 16)->nullable()->after('birth_year');  // one of Patient::COLORS
            $table->string('emoji', 16)->nullable()->after('color');       // null: the name's initial
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar');
        });
        Schema::table('patients', function (Blueprint $table) {
            $table->dropColumn(['color', 'emoji']);
        });
    }
};
