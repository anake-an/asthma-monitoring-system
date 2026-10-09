<?php

namespace App\Policies;

use App\Models\Device;
use App\Models\Patient;
use App\Models\User;

/**
 * Who may do what with a device (one room). The account that paired a device always has full
 * control of it; other accounts get the access their role on the device's patient allows.
 * A shared-room device (no patient) is only visible to its owner.
 *
 *   view       live data, cough history            owner, or anyone who can view its patient
 *   logDose    mark coughs, log a dose from one     owner, owner/caregiver of its patient
 *   configure  limits, buzzer, rename, remove       owner, owner of its patient
 */
class DevicePolicy
{
    public function view(User $user, Device $device): bool
    {
        return $this->role($user, $device) !== null;
    }

    public function logDose(User $user, Device $device): bool
    {
        return in_array($this->role($user, $device), [Patient::OWNER, Patient::CAREGIVER], true);
    }

    public function configure(User $user, Device $device): bool
    {
        return $this->role($user, $device) === Patient::OWNER;
    }

    private function role(User $user, Device $device): ?string
    {
        if ((int) $device->user_id === (int) $user->id) {
            return Patient::OWNER;
        }

        return $device->patient?->roleOf($user);
    }
}
