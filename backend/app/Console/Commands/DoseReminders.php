<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Models\HardwareConfig;
use App\Support\Mqtt;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps the "daily dose?" reminder on each device's LCD up to date. The reminder is the dose_due
 * field of the retained config (HardwareConfig::toDevicePayload): true when the room's child uses
 * a daily inhaler and none was logged for 26 h. Time passing turns it on, so it is checked on a
 * schedule; a device's config is re-published only when its value changes. Logging a daily dose
 * re-publishes at once (DashboardController::logManualInhaler).
 *
 * Scheduled every 5 minutes in routes/console.php.
 */
class DoseReminders extends Command
{
    protected $signature = 'devices:dose-reminders';

    protected $description = 'Send each device whether its child\'s daily dose is overdue (LCD reminder)';

    public function handle(Mqtt $mqtt)
    {
        $sent = 0;
        foreach (Device::whereNotNull('patient_id')->whereNotNull('user_id')->get() as $device) {
            $due = HardwareConfig::missedDailyDose($device->patient_id, now());
            if (Cache::get(self::cacheKey($device)) === $due) {
                continue;
            }
            try {
                $mqtt->publishConfig($device, HardwareConfig::forDevice($device)); // also remembers $due
                $sent++;
            } catch (\Throwable $e) {
                $this->error("Device {$device->id}: MQTT publish failed: " . $e->getMessage());
            }
        }
        $this->info("Dose reminders sent: {$sent}");

        return self::SUCCESS;
    }

    /** The dose_due value last sent to a device (Mqtt::publishConfig keeps it). */
    public static function cacheKey(Device $device): string
    {
        return "dose_due_sent:{$device->id}";
    }
}
