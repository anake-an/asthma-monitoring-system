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
        'device_id',
        'pm25_threshold', 'pm25_cap', 'pm25_locked',
        'temperature_threshold', 'temperature_cap', 'temperature_locked',
        'humidity_threshold', 'humidity_cap', 'humidity_locked',
        'mq135_threshold', 'mq135_cap', 'mq135_locked',
        'pm25_day_start', 'temperature_day_start', 'humidity_day_start', 'mq135_day_start', 'ai_day_started_at',
        'missed_dose_base',
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
        'missed_dose_base' => 'array',
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
        // While the missed-dose rule is on, work on the limits without it; a new value then becomes
        // part of that base and the device gets it 15 % lower (unless locked) until a dose is logged.
        $ruleOn = $this->missed_dose_base !== null;
        $aiWasOn = (bool) $this->ai_optimization_enabled;
        $this->withoutMissedDoseRule();
        $this->fill($changes);

        $reason = 'Changed in Smart Alerts';
        if (!$this->ai_optimization_enabled) {
            // Automatic limits off: every limit is the user's own value again, and the rule stops
            // (ai:optimize no longer runs for this account, so nothing else would undo either).
            foreach (self::LIMITS as $name) {
                $this->{"{$name}_threshold"} = $this->capFor($name);
            }
            $this->applyMissedDoseRule(false);
            if ($aiWasOn) {
                $reason = 'AI optimization turned off: back to your limits';
            }
        } elseif ($ruleOn) {
            $this->applyMissedDoseRule(true);
            $reason = 'Changed in Smart Alerts (15 % lower while a daily dose is missed)';
        }

        $limitChanges = $this->pendingLimitChanges();
        $this->save();
        $this->recordLimitChanges($limitChanges, 'user', $reason);
    }

    /** Missed daily dose rule (DESIGN_MULTI_PATIENT.md 5.5): a documented rule, not AI. */
    public const MISSED_DOSE_FACTOR = 0.85;

    public const MISSED_DOSE_ON = 'Daily inhaler dose missed: limits 15 % lower until one is logged';

    public const MISSED_DOSE_OFF = 'Daily inhaler dose logged: limits back up';

    /**
     * True when this patient uses a daily (controller) inhaler, i.e. one was logged in the last 7 days,
     * but none in the last 26 h (a day plus 2 h of grace). Patients without daily doses, and shared
     * rooms (no patient), are never affected.
     */
    public static function missedDailyDose(?int $patientId, \DateTimeInterface $now): bool
    {
        if (!$patientId) {
            return false;
        }
        $now = \Carbon\Carbon::instance($now);
        $controller = fn () => InhalerLog::where('patient_id', $patientId)->where('type', 'controller');

        return $controller()->where('administered_at', '>=', $now->copy()->subDays(7))->exists()
            && !$controller()->where('administered_at', '>=', $now->copy()->subHours(26))->exists();
    }

    /** In memory: put back the limits without the missed-dose rule, so the AI works on those. */
    public function withoutMissedDoseRule(): void
    {
        foreach ((array) $this->missed_dose_base as $name => $value) {
            if (in_array($name, self::LIMITS, true)) {
                $this->{"{$name}_threshold"} = (float) $value;
            }
        }
    }

    /**
     * In memory: switch the rule on (remember the current limits as the base, lower each unlocked
     * one by 15 %) or off (the current limits are the base already). The caller saves.
     */
    public function applyMissedDoseRule(bool $on): void
    {
        if (!$on) {
            $this->missed_dose_base = null;

            return;
        }
        $base = [];
        foreach (self::LIMITS as $name) {
            $base[$name] = (float) $this->{"{$name}_threshold"};
            if (!$this->{"{$name}_locked"}) {
                $this->{"{$name}_threshold"} = round($base[$name] * self::MISSED_DOSE_FACTOR, 1);
            }
        }
        $this->missed_dose_base = $base;
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

    /**
     * Write one LimitChange row per changed limit, all with the same time, so the Activity Log
     * can show one update as one line.
     */
    public function recordLimitChanges(array $changes, string $source, ?string $reason): void
    {
        $now = now();
        foreach ($changes as $name => [$old, $new]) {
            LimitChange::create([
                'user_id' => $this->user_id,
                'device_id' => $this->device_id,
                'limit_name' => $name,
                'old_value' => $old,
                'new_value' => $new,
                'source' => $source,
                'reason' => $reason,
                'created_at' => $now,
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
     *   - already moved by the AI in the current 24-hour window: unchanged until the next window,
     *     so a room flipping between "normal" and "unusual" does not swing it (or the log) all day;
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
            $current = (float) $this->{"{$name}_threshold"};
            $reference = (float) ($this->{"{$name}_day_start"} ?? $current);
            if (abs($current - $reference) >= 0.05) { // one AI change per limit per window
                $limits["{$name}_threshold"] = min($cap, $current);
                continue;
            }
            $suggestion = isset($suggested["{$name}_threshold"]) ? (float) $suggested["{$name}_threshold"] : $cap;
            $budgeted = max($reference * (1 - self::AI_MAX_DAILY_CHANGE), min($reference * (1 + self::AI_MAX_DAILY_CHANGE), $suggestion));
            $limits["{$name}_threshold"] = round(min($cap, $budgeted), 1);
        }

        return $limits;
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function device()
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * Each device (room) has its own limits, created on first use. A new room of a child starts from
     * the user's own values of that child's most recently changed room (patient defaults, design
     * section 2), otherwise from DEFAULTS. The AI state (day budget, rule) always starts fresh.
     */
    public static function forDevice(Device $device): self
    {
        return static::firstOrCreate(['device_id' => $device->id], ['user_id' => $device->user_id] + self::seedFor($device));
    }

    private static function seedFor(Device $device): array
    {
        $sibling = $device->patient_id
            ? static::whereHas('device', fn ($q) => $q->where('patient_id', $device->patient_id))->latest('updated_at')->first()
            : null;
        if (!$sibling) {
            return self::DEFAULTS;
        }
        $seed = ['is_buzzer_muted' => $sibling->is_buzzer_muted, 'ai_optimization_enabled' => $sibling->ai_optimization_enabled];
        foreach (self::LIMITS as $name) {
            $seed["{$name}_cap"] = $seed["{$name}_threshold"] = $sibling->capFor($name);
            $seed["{$name}_locked"] = (bool) $sibling->{"{$name}_locked"};
        }

        return $seed;
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
            // The room's child usually takes a daily dose and none is logged for 26 h: the LCD shows
            // a reminder (devices:dose-reminders re-sends this when it changes).
            'dose_due' => self::missedDailyDose($this->device?->patient_id, now()),
        ];
    }
}
