<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A change of a device's effective alert limit, by the AI, the user or a rule. See HardwareConfig::recordLimitChanges.
 */
class LimitChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'device_id', 'limit_name', 'old_value', 'new_value', 'source', 'reason', 'created_at'];

    protected $casts = [
        'old_value' => 'float',
        'new_value' => 'float',
        'created_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }
}
