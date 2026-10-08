<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InhalerLog extends Model
{
    protected $fillable = [
        'administered_at',
        'is_manual',
        'type',
        'cough_event_id'
    ];

    protected $casts = [
        'administered_at' => 'datetime',
        'is_manual' => 'boolean',
    ];

    public $timestamps = false; // We use administered_at
}
