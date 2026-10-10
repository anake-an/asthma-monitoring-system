<?php

namespace App\Console\Commands;

use App\Models\Device;
use App\Notifications\DeviceOfflineNotification;
use App\Services\DeviceMessageHandler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Emails and pushes a room's alert recipients (the same people as cough and limit alerts) when
 * its device has sent nothing for Device::OFFLINE_ALERT_MINUTES, e.g. a power cut or Wi-Fi
 * failure at night, when nobody looks at the dashboard. Once per outage: the next message from
 * the device clears offline_alerted_at and sends a "back online" push
 * (DeviceMessageHandler::handle). Devices that never connected are left alone.
 *
 * Scheduled every 5 minutes in routes/console.php.
 */
class DeviceOfflineAlerts extends Command
{
    protected $signature = 'devices:offline-alerts';

    protected $description = 'Alert when a device has been offline for ' . Device::OFFLINE_ALERT_MINUTES . ' minutes';

    public function handle()
    {
        $silent = Device::whereNotNull('user_id')
            ->whereNotNull('last_seen_at')
            ->whereNull('offline_alerted_at')
            ->where('last_seen_at', '<', now()->subMinutes(Device::OFFLINE_ALERT_MINUTES))
            ->get();

        foreach ($silent as $device) {
            $device->forceFill(['offline_alerted_at' => now()])->save();
            foreach (DeviceMessageHandler::alertRecipients($device) as $user) {
                try {
                    $user->notify(new DeviceOfflineNotification($device));
                } catch (\Throwable $e) {
                    Log::error('Failed to send offline alert', ['device_id' => $device->id, 'user_id' => $user->id, 'error' => $e->getMessage()]);
                }
            }
        }
        $this->info("Offline alerts sent for {$silent->count()} device(s)");

        return self::SUCCESS;
    }
}
