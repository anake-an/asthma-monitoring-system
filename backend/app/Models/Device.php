<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One RespiroSync unit = one room. user_id is the account that paired it (its owner);
 * patient_id is the child in that room, or NULL for a shared room (coughs are shown and
 * alerted but not attributed to a child, DESIGN_MULTI_PATIENT.md section 2).
 */
class Device extends Model
{
    use HasFactory;

    /** No message for this long = offline (the device sends telemetry every 3 s). */
    public const OFFLINE_AFTER_SECONDS = 20;

    protected $fillable = [
        'user_id',
        'patient_id',
        'device_token',
        'mac_address',
        'name',
        'status',
        'last_seen_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // A device created without naming a patient belongs to its owner's default patient;
        // pass patient_id => null explicitly for a shared room.
        static::creating(function (Device $device) {
            if (!array_key_exists('patient_id', $device->getAttributes()) && $device->user_id) {
                $device->patient_id = User::find($device->user_id)?->defaultPatient()->id;
            }
        });
    }

    /** pending (never connected), online, or offline (no message for OFFLINE_AFTER_SECONDS). */
    protected function status(): Attribute
    {
        return Attribute::get(function ($value) {
            if (!$this->last_seen_at) {
                return $value ?: 'pending';
            }

            return $this->last_seen_at->gt(now()->subSeconds(self::OFFLINE_AFTER_SECONDS)) ? 'online' : 'offline';
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function patient()
    {
        return $this->belongsTo(Patient::class);
    }

    public function config()
    {
        return $this->hasOne(HardwareConfig::class);
    }

    public function telemetryLogs()
    {
        return $this->hasMany(TelemetryLog::class);
    }

    public function coughEvents()
    {
        return $this->hasMany(CoughEvent::class);
    }

    public function inhalerLogs()
    {
        return $this->hasMany(InhalerLog::class);
    }
}
