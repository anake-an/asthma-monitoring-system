<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TelemetryLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'pm25_level',
        'temperature',
        'humidity',
        'mq135_level',
        'recorded_at',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'pm25_level' => 'float',
        'temperature' => 'float',
        'humidity' => 'float',
        'mq135_level' => 'float',
    ];
}
