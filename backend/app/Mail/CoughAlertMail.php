<?php

namespace App\Mail;

use App\Models\CoughEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class CoughAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public CoughEvent $event, public int $clusterCount = 1)
    {
    }

    public function build()
    {
        return $this->subject('RespiroSync alert: repeated coughing detected')
            ->view('emails.cough_alert')
            ->with([
                'clusterCount' => $this->clusterCount,
                'windowMinutes' => \App\Services\DeviceMessageHandler::WINDOW_MINUTES,
                // Pico detection strength (heuristic 0..1). Null means the device did not report one.
                'strength' => $this->event->confidence,
                'dashboardUrl' => config('services.frontend.url'),
            ]);
    }
}
