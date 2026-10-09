<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HardwareConfig extends Model
{
    use HasFactory;

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    public const DEFAULTS = [
        'pm25_threshold' => 35.0,
        'temperature_threshold' => 35.0,
        'humidity_threshold' => 60.0,
        'mq135_threshold' => 1000.0, // estimated ppm (CO2-equivalent); ~1000 is a common ventilation guide
        'is_buzzer_muted' => false,
        'ai_optimization_enabled' => true,
    ];

    protected $fillable = [
        'user_id',
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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Each account owns exactly one threshold profile, created on first use.
     */
    public static function forUser(User $user): self
    {
        return static::firstOrCreate(['user_id' => $user->id], self::DEFAULTS);
    }

    /**
     * The JSON the ESP32 receives on respirosync/devices/{token}/config.
     */
    public function toDevicePayload(): array
    {
        return [
            'pm25_threshold' => $this->pm25_threshold,
            'temperature_threshold' => $this->temperature_threshold,
            'humidity_threshold' => $this->humidity_threshold,
            'mq135_threshold' => $this->mq135_threshold,
            'is_buzzer_muted' => $this->is_buzzer_muted,
        ];
    }
}
