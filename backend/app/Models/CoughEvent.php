<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoughEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

    /** Logged only (did not meet the alert rule). */
    public const SEVERITY_LOGGED = 1;

    /** Met the alert rule (cough cluster). */
    public const SEVERITY_ALERT = 3;

    protected $fillable = [
        'device_id',
        'severity',
        'confidence',
        'recorded_at',
        'is_verified',
        'inhaler_used',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'severity' => 'float',
        // Heuristic detection strength 0..1 reported by the Pico; null when the device did not send one.
        'confidence' => 'float',
        'is_verified' => 'boolean',
        'inhaler_used' => 'boolean',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
