<?php

namespace App\Policies;

use App\Models\Patient;
use App\Models\User;

/**
 * Who may do what with a patient (DESIGN_MULTI_PATIENT.md section 3). Phase 2 only creates
 * owners; caregiver and viewer arrive with sharing in phase 3 and are already handled here.
 *
 *   view      live data, history, reports         owner, caregiver, viewer
 *   logDose   log doses, mark false alarms        owner, caregiver
 *   manage    rename, assign devices, delete      owner
 */
class PatientPolicy
{
    public function view(User $user, Patient $patient): bool
    {
        return $patient->roleOf($user) !== null;
    }

    public function logDose(User $user, Patient $patient): bool
    {
        return in_array($patient->roleOf($user), [Patient::OWNER, Patient::CAREGIVER], true);
    }

    public function manage(User $user, Patient $patient): bool
    {
        return $patient->roleOf($user) === Patient::OWNER;
    }
}
