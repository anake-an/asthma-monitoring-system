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
        'is_possible',
        'recorded_at',
        'is_verified',
        'inhaler_used',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'severity' => 'float',
        // Cough score 0..1 from the Edge AI model (Edge AI 3.0.0+; older builds sent a loudness
        // heuristic); null when the device did not send one.
        'confidence' => 'float',
        // A possible cough: the model was almost sure (just under the room's threshold). Counts as
        // a cough only once it is reviewed as real; never alerts.
        'is_possible' => 'boolean',
        'is_verified' => 'boolean',
        'inhaler_used' => 'boolean',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Counts as a cough: reviewed as real, or a detection not reviewed yet. Leaves out false alarms
     * and possible coughs nobody has confirmed. What the report, the alert rule and the AI count.
     */
    public function scopeNotFalseAlarm($query)
    {
        return $query->where(fn ($q) => $q->where('is_verified', true)
            ->orWhere(fn ($q) => $q->whereNull('is_verified')->where('is_possible', false)));
    }
}
