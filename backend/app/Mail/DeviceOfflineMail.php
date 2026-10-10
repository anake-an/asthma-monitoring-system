<?php

namespace App\Mail;

use App\Models\Device;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class DeviceOfflineMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Device $device)
    {
    }

    public function build()
    {
        $where = CoughAlertMail::where($this->device);

        return $this->subject("RespiroSync: {$where} is offline")
            ->view('emails.device_offline')
            ->with([
                'where' => $where,
                'minutes' => Device::OFFLINE_ALERT_MINUTES,
                'lastSeen' => $this->device->last_seen_at?->timezone(config('app.timezone'))->format('j M Y, H:i'),
                'dashboardUrl' => config('services.frontend.url') . "/?device={$this->device->id}",
            ]);
    }
}
