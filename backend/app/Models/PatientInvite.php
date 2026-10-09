<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An invitation to see one child, sent to an email address with a role.
 *
 * The emailed token is shown once and only its SHA-256 hash is stored. Accepting needs the
 * token AND an account signed in with the invited email (emails are not verified at sign-up,
 * so the token proves the invitee can read that mailbox). Valid for DAYS days, used once.
 */
class PatientInvite extends Model
{
    public const DAYS = 7;

    protected $fillable = ['patient_id', 'invited_by', 'email', 'role', 'token_hash', 'expires_at', 'accepted_at', 'accepted_by'];

    protected $casts = ['expires_at' => 'datetime', 'accepted_at' => 'datetime'];

    protected $hidden = ['token_hash'];

    /** Create an invite and return [invite, plain token for the email]. */
    public static function issue(Patient $patient, User $inviter, string $email, string $role): array
    {
        $token = Str::random(48);
        $invite = static::create([
            'patient_id' => $patient->id,
            'invited_by' => $inviter->id,
            'email' => strtolower(trim($email)),
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(self::DAYS),
        ]);

        return [$invite, $token];
    }

    /** The open (not used, not expired) invite for a plain token, or null. */
    public static function findOpen(string $token): ?self
    {
        return static::open()->where('token_hash', hash('sha256', $token))->first();
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function inviter()
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
