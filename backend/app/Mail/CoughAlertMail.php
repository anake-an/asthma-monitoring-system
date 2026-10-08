<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;
use App\Models\CoughEvent;

class CoughAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public $event;

    public function __construct(CoughEvent $event)
    {
        $this->event = $event;
    }

    public function build()
    {
        $severityLabel = 'Low';
        if ($this->event->severity == 2) $severityLabel = 'Medium';
        if ($this->event->severity == 3) $severityLabel = 'High';
        
        return $this->subject('⚠️ URGENT: High Severity Asthma Alert Detected')
                    ->view('emails.cough_alert')
                    ->with([
                        'severityLabel' => $severityLabel
                    ]);
    }
}
