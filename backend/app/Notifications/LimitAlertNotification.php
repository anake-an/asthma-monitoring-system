<?php

namespace App\Notifications;

use App\Mail\CoughAlertMail;
use App\Mail\LimitAlertMail;
use App\Models\LimitAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/** "Dust has been above 20 µg/m³ for 5 minutes in Bedroom (Aiman)": email and push. */
class LimitAlertNotification extends Notification
{
    use Queueable;

    public function __construct(public LimitAlert $alert)
    {
    }

    public function via($notifiable)
    {
        return [WebPushChannel::class, 'mail'];
    }

    public function toMail($notifiable)
    {
        return (new LimitAlertMail($this->alert))->to($notifiable->email);
    }

    public function toWebPush($notifiable, $notification)
    {
        $a = $this->alert;
        $where = CoughAlertMail::where($a->device);

        return (new WebPushMessage)
            ->title("RespiroSync: {$a->label()} high")
            ->icon('/icon.jpg')
            ->body("{$a->label()} has been above {$a->format($a->limit_value)} for " . LimitAlert::SUSTAIN_MINUTES
                . " minutes in {$where}. Now {$a->format($a->value)}.")
            ->tag("limit-{$a->device_id}-{$a->reading}") // one per room and reading on the screen
            ->data(['url' => "/?device={$a->device_id}"])
            ->vibrate([100, 50, 100]);
    }
}
