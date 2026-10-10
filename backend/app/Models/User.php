<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use NotificationChannels\WebPush\HasPushSubscriptions;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable, HasPushSubscriptions;

    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    /** Devices this account paired (and owns). */
    public function devices()
    {
        return $this->hasMany(Device::class);
    }

    /** Patients this account can see, with its role in pivot->role. */
    public function patients()
    {
        return $this->belongsToMany(Patient::class)->withPivot('role', 'alerts')->withTimestamps();
    }

    /** Patients this account owns. */
    public function ownedPatients()
    {
        return $this->patients()->wherePivot('role', Patient::OWNER);
    }

    /**
     * The patient used when a request names none: the oldest one this account owns, created as
     * "My child" on first use (new accounts, and accounts from before patients existed).
     */
    public function defaultPatient(): Patient
    {
        $patient = $this->ownedPatients()->orderBy('patients.id')->first();
        if ($patient) {
            return $patient;
        }
        $patient = Patient::create(['name' => Patient::DEFAULT_NAME]);
        $this->patients()->attach($patient->id, ['role' => Patient::OWNER]);

        return $patient;
    }

    /**
     * Every device this account may see: the ones it paired, plus the devices of patients it has
     * access to. The single query for device visibility; abilities are in App\Policies.
     */
    public function accessibleDevices()
    {
        $patientIds = $this->patients()->pluck('patients.id');

        return Device::query()->where(fn ($q) => $q->where('user_id', $this->id)->orWhereIn('patient_id', $patientIds));
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }

    public function hardwareConfig()
    {
        return $this->hasOne(HardwareConfig::class);
    }

    /**
     * Cough events from every device this account owns.
     */
    public function coughEvents()
    {
        return $this->hasManyThrough(CoughEvent::class, Device::class);
    }
}
