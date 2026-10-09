<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A change of an effective alert limit, by the AI or by the user. See HardwareConfig::recordLimitChanges.
 */
class LimitChange extends Model
{
    public $timestamps = false;

    protected $fillable = ['user_id', 'limit_name', 'old_value', 'new_value', 'source', 'reason', 'created_at'];

    protected $casts = [
        'old_value' => 'float',
        'new_value' => 'float',
        'created_at' => 'datetime',
    ];
}
