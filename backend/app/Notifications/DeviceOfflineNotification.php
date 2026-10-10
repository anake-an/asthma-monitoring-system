<?php

namespace App\Notifications;

use App\Mail\CoughAlertMail;
use App\Mail\DeviceOfflineMail;
use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** "Bedroom (Aiman) has been offline for 30 minutes": email and push. */
class DeviceOfflineNotification extends Notification
{
    use Queueable;

    public function __construct(public Device $device)
    {
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new DeviceOfflineMail($this->device))->to($notifiable->email);
    }

    public function toWebPush($notifiable, $notification)
    {
        $where = CoughAlertMail::where($this->device);

        return (new WebPushMessage)
            ->title('RespiroSync: device offline')
            ->icon('/icon.jpg')
            ->body("{$where} has sent nothing for " . Device::OFFLINE_ALERT_MINUTES
                . ' minutes: no readings and no alerts from that room. Check its power and Wi-Fi.')
            ->tag("offline-{$this->device->id}") // replaced by the "back online" push
            ->data(['url' => "/?device={$this->device->id}"])
            ->vibrate([100, 50, 100]);
    }
}
