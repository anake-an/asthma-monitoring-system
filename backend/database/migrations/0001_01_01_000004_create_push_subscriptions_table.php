<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * From laravel-notification-channels/webpush. Guarded with hasTable() so it is
 * safe on databases created before this file was in git.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection(config('webpush.database_connection'));
        $table = config('webpush.table_name');

        if (! $schema->hasTable($table)) {
            $schema->create($table, function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->morphs('subscribable');
                $table->string('endpoint', 500)->unique();
                $table->string('public_key')->nullable();
                $table->string('auth_token')->nullable();
                $table->string('content_encoding')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))->dropIfExists(config('webpush.table_name'));
    }
};
