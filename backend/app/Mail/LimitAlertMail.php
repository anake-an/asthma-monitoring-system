<?php

namespace App\Mail;

use App\Models\LimitAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class LimitAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public LimitAlert $alert)
    {
    }

    public function build()
    {
        $a = $this->alert;

        return $this->subject("RespiroSync alert: {$a->label()} above the limit")
            ->view('emails.limit_alert')
            ->with([
                'reading' => $a->label(),
                'value' => $a->format($a->value),
                'limit' => $a->format($a->limit_value),
                'minutes' => LimitAlert::SUSTAIN_MINUTES,
                'repeat' => LimitAlert::REPEAT_MINUTES,
                'where' => CoughAlertMail::where($a->device),
                'dashboardUrl' => config('services.frontend.url') . "/?device={$a->device_id}",
            ]);
    }
}
