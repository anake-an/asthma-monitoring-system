<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushMessage;
use NotificationChannels\WebPush\WebPushChannel;

class CoughAlertNotification extends Notification
{
    use Queueable;

    public $event;

    public function __construct($event)
    {
        $this->event = $event;
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new \App\Mail\CoughAlertMail($this->event))
                    ->to($notifiable->email);
    }

    public function toWebPush($notifiable, $notification)
    {
        return (new WebPushMessage)
            ->title('Asthma Alert!')
            ->icon('/icon-192x192.jpg')
            ->body('High severity cough detected (Level ' . $this->event->severity . '). Please check on the patient and prepare the inhaler!')
            ->action('View Dashboard', 'view_dashboard')
            ->vibrate([100, 50, 100]);
    }
}
