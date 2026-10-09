<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An inhaler dose. It belongs to the child (patient_id); user_id is the account that logged it.
 */
class InhalerLog extends Model
{
    protected $fillable = [
        'user_id',
        'patient_id',
        'device_id',
        'administered_at',
        'is_manual',
        'type',
        'cough_event_id',
    ];

    protected $casts = [
        'administered_at' => 'datetime',
        'is_manual' => 'boolean',
    ];

    public $timestamps = false; // We use administered_at

    protected static function booted(): void
    {
        // Without a patient, the dose is for the logger's default patient.
        static::creating(function (InhalerLog $log) {
            if (!array_key_exists('patient_id', $log->getAttributes()) && $log->user_id) {
                $log->patient_id = User::find($log->user_id)?->defaultPatient()->id;
            }
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
}
