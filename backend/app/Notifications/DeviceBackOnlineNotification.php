<?php

namespace App\Notifications;

use App\Mail\CoughAlertMail;
use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** After an offline alert: "Bedroom (Aiman) is back online". Push only, replacing the offline one. */
class DeviceBackOnlineNotification extends Notification
{
    use Queueable;

    public function __construct(public Device $device)
    {
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class];
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('RespiroSync: back online')
            ->icon('/icon.jpg')
            ->body(CoughAlertMail::where($this->device) . ' is sending readings again.')
            ->tag("offline-{$this->device->id}")
            ->data(['url' => "/?device={$this->device->id}"]);
    }
}
