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
    public const CAREGIVER = 'caregiver'; // view + log doses / mark false alarms (phase 3)
    public const VIEWER = 'viewer';       // view only (phase 3)

    public const DEFAULT_NAME = 'My child';

    protected $fillable = ['name', 'birth_year'];

    protected $casts = ['birth_year' => 'integer'];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }

    /** This user's role for the patient, or null when they have no access. */
    public function roleOf(User $user): ?string
    {
        return $this->users()->where('users.id', $user->id)->value('patient_user.role');
    }
}
