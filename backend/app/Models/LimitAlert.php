<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An alert sent because a room's reading stayed above its limit for SUSTAIN_MINUTES
 * (App\Services\DeviceMessageHandler::checkLimits).
 */
class LimitAlert extends Model
{
    /** How long a reading has to stay above its limit before anyone is alerted. */
    public const SUSTAIN_MINUTES = 5;

    /** While it stays high, a reminder at most this often (per room and reading). */
    public const REPEAT_MINUTES = 60;

    /** Reading name => [telemetry column, label, unit]. */
    public const READINGS = [
        'pm25' => ['pm25_level', 'Dust (PM2.5)', ' µg/m³'],
        'mq135' => ['mq135_level', 'Gas', ' ppm'],
        'temperature' => ['temperature', 'Temperature', ' °C'],
        'humidity' => ['humidity', 'Humidity', ' %'],
    ];

    public $timestamps = false;

    protected $fillable = ['device_id', 'reading', 'value', 'limit_value', 'created_at'];

    protected $casts = ['value' => 'float', 'limit_value' => 'float', 'created_at' => 'datetime'];

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /** "Dust (PM2.5)". */
    public function label(): string
    {
        return self::READINGS[$this->reading][1] ?? $this->reading;
    }

    /** "27.3 µg/m³" (gas without decimals). */
    public function format(float $value): string
    {
        $unit = self::READINGS[$this->reading][2] ?? '';

        return ($this->reading === 'mq135' ? (string) round($value) : (string) round($value, 1)) . $unit;
    }
}
