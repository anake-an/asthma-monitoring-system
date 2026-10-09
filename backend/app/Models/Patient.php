<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The child being monitored (DESIGN_MULTI_PATIENT.md section 2). Stores a display name and an
 * optional birth year only: it is a child's health record (PDPA data minimisation).
 *
 * Access goes through patient_user roles and App\Policies\PatientPolicy, never ad-hoc checks.
 */
class Patient extends Model
{
    public const OWNER = 'owner';         // everything, incl. limits, devices, sharing, deletion
    public const CAREGIVER = 'caregiver'; // view + log doses / mark false alarms
    public const VIEWER = 'viewer';       // view only; alerts off by default

    public const ROLES = [self::OWNER, self::CAREGIVER, self::VIEWER];

    public const DEFAULT_NAME = 'My child';

    protected $fillable = ['name', 'birth_year'];

    protected $casts = ['birth_year' => 'integer'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role', 'alerts')->withTimestamps();
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }

    public function invites()
    {
        return $this->hasMany(PatientInvite::class);
    }

    /** Members who get cough alerts for this child (each can switch their own off). */
    public function alertRecipients()
    {
        return $this->users()->wherePivot('alerts', true)->get();
    }

    public function ownerCount(): int
    {
        return $this->users()->wherePivot('role', self::OWNER)->count();
    }

    /** This user's role for the patient, or null when they have no access. */
    public function roleOf(User $user): ?string
    {
        return $this->users()->where('users.id', $user->id)->value('patient_user.role');
    }
}
