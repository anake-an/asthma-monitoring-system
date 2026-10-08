<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HardwareConfig extends Model
{
    use HasFactory;

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    protected $fillable = [
        'pm25_threshold',
        'temperature_threshold',
        'humidity_threshold',
        'mq135_threshold',
        'is_buzzer_muted',
        'ai_optimization_enabled',
    ];

    protected $casts = [
        'pm25_threshold' => 'float',
        'temperature_threshold' => 'float',
        'humidity_threshold' => 'float',
        'mq135_threshold' => 'float',
        'is_buzzer_muted' => 'boolean',
        'ai_optimization_enabled' => 'boolean',
    ];
}
