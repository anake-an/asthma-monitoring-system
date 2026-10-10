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
        // Which room and child, so someone who follows several children knows where to go.
        $device = $this->event->device;
        $where = $device ? ' in ' . $device->name . ($device->patient ? " ({$device->patient->name})" : '') : '';

        return (new WebPushMessage)
            ->title('RespiroSync cough alert')
            ->icon('/icon.jpg')
            ->body("{$this->clusterCount} coughs detected{$where} in the last 10 minutes. Please check on them.")
            ->action('View Dashboard', 'view_dashboard')
            ->tag('cough-' . ($device->id ?? 'test')) // a newer alert for the same room replaces the older one
            ->data(['url' => '/' . ($device ? "?device={$device->id}" : '')])
            ->vibrate([100, 50, 100]);
    }
}
