<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A sensor reading. Raw readings (every 3 s) are kept for RAW_DAYS, then replaced by one average
 * per device and BUCKET_MINUTES (samples = how many readings it replaced); everything older than
 * KEEP_DAYS is deleted. See App\Console\Commands\TelemetryPrune (nightly).
 */
class TelemetryLog extends Model
{
    use HasFactory;

    public const RAW_DAYS = 7;

    public const BUCKET_MINUTES = 10; // the AI's training window, so averages train it the same way

    public const KEEP_DAYS = 365;

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'pm25_level',
        'temperature',
        'humidity',
        'mq135_level',
        'recorded_at',
        'samples',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'pm25_level' => 'float',
        'temperature' => 'float',
        'humidity' => 'float',
        'mq135_level' => 'float',
        'samples' => 'integer',
    ];
}
