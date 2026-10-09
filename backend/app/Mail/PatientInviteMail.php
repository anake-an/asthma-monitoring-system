<?php

namespace App\Mail;

use App\Models\PatientInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

/** "X invited you to follow <child> on RespiroSync", with the one-time accept link. */
class PatientInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public const ROLE_TEXT = [
        'owner' => 'as an owner (full control, including alert limits and sharing)',
        'caregiver' => 'as a caregiver (see readings and alerts, log inhaler doses)',
        'viewer' => 'as a viewer (see readings and reports)',
    ];

    public function __construct(public PatientInvite $invite, public string $token)
    {
    }

    public function build()
    {
        $inviter = $this->invite->inviter->name;
        $child = $this->invite->patient->name;

        return $this->subject("{$inviter} shared {$child} with you on RespiroSync")
            ->view('emails.patient_invite')
            ->with([
                'inviter' => $inviter,
                'child' => $child,
                'roleText' => self::ROLE_TEXT[$this->invite->role] ?? $this->invite->role,
                'days' => PatientInvite::DAYS,
                'email' => $this->invite->email,
                'url' => config('services.frontend.url') . '/invite?token=' . urlencode($this->token),
            ]);
    }
}
