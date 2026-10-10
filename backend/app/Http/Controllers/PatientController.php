<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Patient;
use App\Support\AiEngine;
use Illuminate\Http\Request;

/**
 * Patients (children) this user can see. Only a display name and an optional birth year are
 * stored (PDPA data minimisation). Changes need "manage" (PatientPolicy, owner only).
 */
class PatientController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        $user->defaultPatient(); // every account has at least one patient

        $patients = $user->patients()->with('devices:id,patient_id,name,last_seen_at,status')->orderBy('patients.id')->get()
            ->map(fn (Patient $p) => [
                'id' => $p->id,
                'name' => $p->name,
                'birth_year' => $p->birth_year,
                'role' => $p->pivot->role,
                'alerts' => (bool) $p->pivot->alerts, // my own cough alerts for this child
                'devices' => $p->devices->map->only(['id', 'name', 'status'])->values(),
            ]);

        return response()->json(['patients' => $patients]);
    }

    public function store(Request $request)
    {
        $validated = $this->validated($request);
        $patient = Patient::create($validated);
        $patient->users()->attach($request->user()->id, ['role' => Patient::OWNER]);
        AuditLog::record($request->user(), 'patient.created', $patient->id, null, ['name' => $patient->name]);

        return response()->json($patient, 201);
    }

    public function update(Request $request, $id)
    {
        $request->merge(['patient_id' => $id]);
        $patient = $this->patient($request, 'manage');
        $before = $patient->name;
        $patient->update($this->validated($request, partial: true));
        if ($patient->name !== $before) {
            AuditLog::record($request->user(), 'patient.renamed', $patient->id, null, ['from' => $before, 'to' => $patient->name]);
        }

        return response()->json($patient);
    }

    /**
     * Delete a patient and their dose history (cascade). Their rooms stay, as shared rooms
     * (devices.patient_id becomes NULL). The last patient of an account cannot be deleted.
     */
    public function destroy(Request $request, $id)
    {
        $request->merge(['patient_id' => $id]);
        $patient = $this->patient($request, 'manage');
        if ($request->user()->ownedPatients()->count() <= 1) {
            return response()->json(['message' => 'Keep at least one child: rename this one instead.'], 422);
        }
        AuditLog::record($request->user(), 'patient.deleted', $patient->id, null, ['name' => $patient->name]);
        $patient->delete();
        app(AiEngine::class)->forget([], [$patient->id]); // their risk model too (the rooms stay, shared)

        return response()->json(['message' => 'Patient and their dose history deleted']);
    }

    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes|required' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:60",
            'birth_year' => 'sometimes|nullable|integer|min:1990|max:' . now()->year,
        ]);
    }
}
