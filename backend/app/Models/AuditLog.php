<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who did what (DESIGN_MULTI_PATIENT.md section 3): sharing, children, rooms, limits, doses and
 * false-alarm marks. Rows outlive the actor, child or room (ids become NULL; details keep names).
 */
class AuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'patient_id', 'device_id', 'action', 'details', 'created_at'];

    protected $casts = ['details' => 'array', 'created_at' => 'datetime'];

    public static function record(?User $actor, string $action, ?int $patientId = null, ?int $deviceId = null, array $details = []): self
    {
        return static::create([
            'user_id' => $actor?->id,
            'patient_id' => $patientId,
            'device_id' => $deviceId,
            'action' => $action,
            'details' => $details ?: null,
            'created_at' => now(),
        ]);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
