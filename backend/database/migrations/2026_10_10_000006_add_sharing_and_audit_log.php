<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Multi-patient phase 3: sharing a child with other accounts (DESIGN_MULTI_PATIENT.md section 3).
 *
 *  - patient_invites: an owner invites an email to one child with a role. Only a SHA-256 hash of
 *    the emailed token is stored; an invite expires after 7 days and is used once.
 *  - patient_user.alerts: whether this member gets cough alerts (owner/caregiver on, viewer off).
 *  - audit_logs: who did what (sharing, children, rooms, limits, doses), kept when the actor,
 *    child or room is deleted later (their ids become NULL; the details keep the names).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->string('email');
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['patient_id', 'email']);
        });

        Schema::table('patient_user', function (Blueprint $table) {
            $table->boolean('alerts')->default(true)->after('role');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();      // who did it
            $table->foreignId('patient_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action', 40);                                                   // e.g. invite.sent
            $table->json('details')->nullable();
            $table->timestamp('created_at');                                                 // app clock
            $table->index(['patient_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::table('patient_user', function (Blueprint $table) {
            $table->dropColumn('alerts');
        });
        Schema::dropIfExists('patient_invites');
    }
};
