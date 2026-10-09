<?php

namespace App\Notifications;

use App\Mail\CoughAlertMail;
use App\Models\CoughEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

class CoughAlertNotification extends Notification
{
    use Queueable;

    public function __construct(public CoughEvent $event, public int $clusterCount = 1)
    {
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new CoughAlertMail($this->event, $this->clusterCount))
            ->to($notifiable->email);
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('RespiroSync cough alert')
            ->icon('/icon.jpg')
            ->body("{$this->clusterCount} coughs detected in the last 10 minutes. Please check on the patient.")
            ->action('View Dashboard', 'view_dashboard')
            ->vibrate([100, 50, 100]);
    }
}
