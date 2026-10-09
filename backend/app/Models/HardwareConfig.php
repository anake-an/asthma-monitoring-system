<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class HardwareConfig extends Model
{
    use HasFactory;

    const UPDATED_AT = 'updated_at';
    const CREATED_AT = null;

    /**
     * The four alert limits. For each one:
     *   <name>_threshold  effective limit, sent to the device
     *   <name>_cap        the user's own value; the AI may tighten below it, never above it
     *   <name>_locked     the AI never changes this limit (effective = cap)
     */
    public const LIMITS = ['pm25', 'temperature', 'humidity', 'mq135'];

    public const DEFAULTS = [
        'pm25_threshold' => 35.0, 'pm25_cap' => 35.0,
        'temperature_threshold' => 35.0, 'temperature_cap' => 35.0,
        // 75 %: indoor air in Malaysia is commonly above 60 %, which alarmed all the time.
        'humidity_threshold' => 75.0, 'humidity_cap' => 75.0,
        // estimated ppm (CO2-equivalent); ~1000 is a common ventilation guide
        'mq135_threshold' => 1000.0, 'mq135_cap' => 1000.0,
        'pm25_locked' => false, 'temperature_locked' => false, 'humidity_locked' => false, 'mq135_locked' => false,
        'is_buzzer_muted' => false,
        'ai_optimization_enabled' => true,
    ];

    protected $fillable = [
        'user_id',
        'pm25_threshold', 'pm25_cap', 'pm25_locked',
        'temperature_threshold', 'temperature_cap', 'temperature_locked',
        'humidity_threshold', 'humidity_cap', 'humidity_locked',
        'mq135_threshold', 'mq135_cap', 'mq135_locked',
        'pm25_day_start', 'temperature_day_start', 'humidity_day_start', 'mq135_day_start', 'ai_day_started_at',
        'is_buzzer_muted',
        'ai_optimization_enabled',
    ];

    protected $casts = [
        'pm25_threshold' => 'float', 'pm25_cap' => 'float', 'pm25_locked' => 'boolean',
        'temperature_threshold' => 'float', 'temperature_cap' => 'float', 'temperature_locked' => 'boolean',
        'humidity_threshold' => 'float', 'humidity_cap' => 'float', 'humidity_locked' => 'boolean',
        'mq135_threshold' => 'float', 'mq135_cap' => 'float', 'mq135_locked' => 'boolean',
        'pm25_day_start' => 'float', 'temperature_day_start' => 'float', 'humidity_day_start' => 'float', 'mq135_day_start' => 'float',
        'ai_day_started_at' => 'datetime',
        'is_buzzer_muted' => 'boolean',
        'ai_optimization_enabled' => 'boolean',
    ];

    /** The user's own value for a limit (older rows without a cap fall back to the effective limit). */
    public function capFor(string $name): float
    {
        return (float) ($this->{"{$name}_cap"} ?? $this->{"{$name}_threshold"});
    }

    /**
     * Apply the user's values from Smart Alerts: each value becomes the cap and the effective
     * limit (the AI may tighten it again later). Locks are stored as given.
     */
    public function applyUserSettings(array $input): void
    {
        $changes = [];
        foreach (self::LIMITS as $name) {
            if (array_key_exists("{$name}_threshold", $input)) {
                $changes["{$name}_cap"] = (float) $input["{$name}_threshold"];
                $changes["{$name}_threshold"] = (float) $input["{$name}_threshold"];
                // A user change is not AI movement: the AI's daily budget starts again from the new value.
                $changes["{$name}_day_start"] = (float) $input["{$name}_threshold"];
            }
            if (array_key_exists("{$name}_locked", $input)) {
                $changes["{$name}_locked"] = (bool) $input["{$name}_locked"];
            }
        }
        foreach (['is_buzzer_muted', 'ai_optimization_enabled'] as $key) {
            if (array_key_exists($key, $input)) {
                $changes[$key] = (bool) $input[$key];
            }
        }
        $this->fill($changes);
        $limitChanges = $this->pendingLimitChanges();
        $this->save();
        $this->recordLimitChanges($limitChanges, 'user', 'Changed in Smart Alerts');
    }

    /**
     * Effective limits that changed but are not saved yet: [name => [old, new]]. Call before save().
     */
    public function pendingLimitChanges(): array
    {
        $changes = [];
        foreach (self::LIMITS as $name) {
            $key = "{$name}_threshold";
            if ($this->isDirty($key)) {
                $old = $this->getOriginal($key);
                $changes[$name] = [$old === null ? null : (float) $old, (float) $this->{$key}];
            }
        }

        return $changes;
    }

    /** Write one LimitChange row per changed limit (shown in the Activity Log). */
    public function recordLimitChanges(array $changes, string $source, ?string $reason): void
    {
        foreach ($changes as $name => [$old, $new]) {
            LimitChange::create([
                'user_id' => $this->user_id,
                'limit_name' => $name,
                'old_value' => $old,
                'new_value' => $new,
                'source' => $source,
                'reason' => $reason,
                'created_at' => now(),
            ]);
        }
    }

    /** The AI may move a limit by at most this share per 24 h (DESIGN_MULTI_PATIENT.md 5.6). */
    public const AI_MAX_DAILY_CHANGE = 0.10;

    /**
     * Start a new 24-hour budget window when the last one is over: each limit's reference becomes
     * its current effective value. Changes the model in memory; the caller saves it.
     */
    public function startAiDayIfDue(\DateTimeInterface $now): void
    {
        if ($this->ai_day_started_at && $this->ai_day_started_at->gt(\Carbon\Carbon::instance($now)->subDay())) {
            return;
        }
        $this->ai_day_started_at = $now;
        foreach (self::LIMITS as $name) {
            $this->{"{$name}_day_start"} = (float) $this->{"{$name}_threshold"};
        }
    }

    /**
     * The limits the AI may set, given its suggestions. Returns <name>_threshold => value for all four:
     *   - locked: exactly the user's value (cap);
     *   - otherwise the suggestion, within +/-10 % of the limit's value at the start of the current
     *     24-hour window, and never above the cap (the cap wins even mid-window).
     */
    public function limitsFromSuggestion(array $suggested): array
    {
        $limits = [];
        foreach (self::LIMITS as $name) {
            $cap = $this->capFor($name);
            if ($this->{"{$name}_locked"}) {
                $limits["{$name}_threshold"] = $cap;
                continue;
            }
            $suggestion = isset($suggested["{$name}_threshold"]) ? (float) $suggested["{$name}_threshold"] : $cap;
            $reference = (float) ($this->{"{$name}_day_start"} ?? $this->{"{$name}_threshold"});
            $budgeted = max($reference * (1 - self::AI_MAX_DAILY_CHANGE), min($reference * (1 + self::AI_MAX_DAILY_CHANGE), $suggestion));
            $limits["{$name}_threshold"] = round(min($cap, $budgeted), 1);
        }

        return $limits;
    }

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
