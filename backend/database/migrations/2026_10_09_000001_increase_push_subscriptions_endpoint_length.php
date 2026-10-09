<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use NotificationChannels\WebPush\PushSubscription;

/*
 * laravel-notification-channels/webpush 12.1+ allows endpoints up to 1024 characters.
 * Endpoints are URLs, so the column is ASCII: 1024 bytes keeps the unique index well
 * under MySQL's 3072-byte key limit (utf8mb4 would need 4096).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->resize(PushSubscription::ENDPOINT_MAX_LENGTH);
    }

    public function down(): void
    {
        $this->resize(500);
    }

    private function resize(int $length): void
    {
        $tableName = config('webpush.table_name');

        Schema::connection(config('webpush.database_connection'))->table($tableName, function (Blueprint $table) use ($length) {
            $table->dropUnique(['endpoint']);
            $table->string('endpoint', $length)->charset('ascii')->change();
            $table->unique('endpoint');
        });
    }
};
