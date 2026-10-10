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

    /** Silent this long: its alert recipients get a "device offline" email and push (once per outage). */
    public const OFFLINE_ALERT_MINUTES = 30;

    protected $fillable = [
        'user_id',
        'patient_id',
        'device_token',
        'mac_address',
        'name',
        'status',
        'last_seen_at',
        'offline_alerted_at',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'offline_alerted_at' => 'datetime', // set when the offline alert went out, cleared when it is back
        'ota_started_at' => 'datetime',
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

    /** A firmware update with no answer from the device after this long has failed. */
    public const OTA_TIMEOUT_MINUTES = 15;

    /**
     * The last firmware update: status (null, updating, updated, failed), target version, error.
     * "updating" without an answer for OTA_TIMEOUT_MINUTES counts as failed.
     */
    public function otaState(): array
    {
        $status = $this->ota_status;
        $error = $this->ota_error;
        if ($status === 'updating' && $this->ota_started_at?->lt(now()->subMinutes(self::OTA_TIMEOUT_MINUTES))) {
            $status = 'failed';
            $error = 'No answer from the device within ' . self::OTA_TIMEOUT_MINUTES . ' minutes. It still runs its old firmware.';
        }

        return ['status' => $status, 'target' => $this->ota_target_version, 'part' => $this->ota_target ?? 'esp32', 'error' => $error];
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
