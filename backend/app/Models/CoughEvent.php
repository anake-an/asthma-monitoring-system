<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CoughEvent extends Model
{
    use HasFactory;

    public $timestamps = false;

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
        'is_verified' => 'boolean',
        'inhaler_used' => 'boolean',
    ];
}
