<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Registration used to create an empty child "My child" for every new account, including people who
 * only signed up to accept an invitation; they then saw a child of their own and a pairing button.
 * Registration no longer does that. This removes those leftovers, and only those: named "My child",
 * no birth year, no rooms, no doses, no other member, on an account that has access to another
 * child. Anything with data, or someone's only child, is left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->cleanup();
    }

    /** Public so a test can run it against prepared rows. Returns how many were removed. */
    public function cleanup(): int
    {
        $candidates = DB::table('patients')
            ->where('name', 'My child')
            ->whereNull('birth_year')
            ->whereNotExists(fn ($q) => $q->from('devices')->whereColumn('devices.patient_id', 'patients.id'))
            ->whereNotExists(fn ($q) => $q->from('inhaler_logs')->whereColumn('inhaler_logs.patient_id', 'patients.id'))
            ->pluck('id');

        $removed = 0;
        foreach ($candidates as $patientId) {
            $members = DB::table('patient_user')->where('patient_id', $patientId)->get();
            if ($members->count() !== 1 || $members[0]->role !== 'owner') {
                continue;
            }
            $hasOtherChild = DB::table('patient_user')
                ->where('user_id', $members[0]->user_id)->where('patient_id', '!=', $patientId)->exists();
            if (!$hasOtherChild) {
                continue; // someone's only child: keep it, they may pair a device for it
            }
            DB::table('patients')->where('id', $patientId)->delete(); // patient_user rows cascade
            $removed++;
        }

        return $removed;
    }

    public function down(): void
    {
        // Nothing to restore: the removed rows held no data.
    }
};
