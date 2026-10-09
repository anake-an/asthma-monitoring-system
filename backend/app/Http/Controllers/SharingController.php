<?php

namespace App\Http\Controllers;

use App\Mail\PatientInviteMail;
use App\Models\AuditLog;
use App\Models\Patient;
use App\Models\PatientInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

/**
 * Sharing a child with other accounts (DESIGN_MULTI_PATIENT.md section 3): members and their
 * roles, email invites, leaving, per-member alerts, and the audit log. Access through
 * Controller::patient() and PatientPolicy; removing someone takes effect on their next request.
 */
class SharingController extends Controller
{
    /** Members of a child; owners also see open invites. */
    public function members(Request $request, $id)
    {
        $patient = $this->patientById($request, $id);
        $user = $request->user();

        return response()->json([
            'my_role' => $patient->roleOf($user),
            'members' => $patient->users()->orderBy('users.id')->get()->map(fn (User $u) => [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'role' => $u->pivot->role,
                'alerts' => (bool) $u->pivot->alerts,
                'is_me' => (int) $u->id === (int) $user->id,
            ]),
            'invites' => $user->can('manage', $patient)
                ? $patient->invites()->open()->latest()->get(['id', 'email', 'role', 'expires_at'])
                : [],
        ]);
    }

    /** Invite an email to this child with a role; the link is emailed (owners only). */
    public function invite(Request $request, $id)
    {
        $patient = $this->patientById($request, $id, 'manage');
        $validated = $request->validate([
            'email' => 'required|email|max:255',
            'role' => ['required', Rule::in(Patient::ROLES)],
        ]);
        $email = strtolower(trim($validated['email']));

        if ($patient->users()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
            return response()->json(['message' => 'This person already has access: change their role instead.'], 422);
        }

        // A new invite replaces an open one for the same email.
        $patient->invites()->open()->where('email', $email)->delete();
        [$invite, $token] = PatientInvite::issue($patient, $request->user(), $email, $validated['role']);

        try {
            Mail::to($email)->send(new PatientInviteMail($invite, $token));
        } catch (\Throwable $e) {
            $invite->delete();
            Log::error('Invite email failed', ['patient_id' => $patient->id, 'error' => $e->getMessage()]);

            return response()->json(['message' => 'The invitation email could not be sent. Check the mail settings and try again.'], 502);
        }

        AuditLog::record($request->user(), 'invite.sent', $patient->id, null, ['email' => $email, 'role' => $invite->role]);

        return response()->json($invite->only(['id', 'email', 'role', 'expires_at']), 201);
    }

    /** Cancel an open invite (owners only). */
    public function cancelInvite(Request $request, $inviteId)
    {
        $invite = PatientInvite::open()->find($inviteId);
        $this->authorizeOr404($request, $invite?->patient, 'manage');
        $invite->delete();
        AuditLog::record($request->user(), 'invite.cancelled', $invite->patient_id, null, ['email' => $invite->email]);

        return response()->json(['message' => 'Invitation cancelled']);
    }

    /** What an invite link offers, for the signed-in user to accept or ignore. */
    public function showInvite(Request $request, string $token)
    {
        $invite = PatientInvite::findOpen($token) ?? abort(404, 'This invitation is invalid, used or expired.');

        return response()->json([
            'child' => $invite->patient->name,
            'invited_by' => $invite->inviter->name,
            'role' => $invite->role,
            'email' => $invite->email,
            'expires_at' => $invite->expires_at,
            'email_matches' => $this->emailMatches($request->user(), $invite),
        ]);
    }

    /**
     * Accept an invite: needs the emailed token and an account signed in with the invited email
     * (emails are not verified at sign-up, so the token proves access to that mailbox).
     */
    public function acceptInvite(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        $invite = PatientInvite::findOpen($request->token) ?? abort(404, 'This invitation is invalid, used or expired.');
        $user = $request->user();

        if (!$this->emailMatches($user, $invite)) {
            return response()->json(['message' => "This invitation is for {$invite->email}. Sign in with that email to accept it."], 403);
        }

        // Already a member: the invite is used up but the role stays (an owner accepting a viewer
        // invite must not leave the child without an owner; owners change roles explicitly).
        if (!$invite->patient->users()->where('users.id', $user->id)->exists()) {
            $invite->patient->users()->attach($user->id, ['role' => $invite->role, 'alerts' => $invite->role !== Patient::VIEWER]);
        }
        $invite->update(['accepted_at' => now(), 'accepted_by' => $user->id]);
        AuditLog::record($user, 'invite.accepted', $invite->patient_id, null, ['role' => $invite->role]);

        return response()->json(['message' => 'Invitation accepted', 'patient_id' => $invite->patient_id]);
    }

    /** Change a member's role (owners only). A child always keeps at least one owner. */
    public function updateMember(Request $request, $id, $userId)
    {
        $patient = $this->patientById($request, $id, 'manage');
        $validated = $request->validate(['role' => ['required', Rule::in(Patient::ROLES)]]);
        $member = $patient->users()->where('users.id', $userId)->first() ?? abort(404);

        if ($member->pivot->role === Patient::OWNER && $validated['role'] !== Patient::OWNER && $patient->ownerCount() <= 1) {
            return response()->json(['message' => 'A child needs at least one owner: make someone else owner first.'], 422);
        }
        $patient->users()->updateExistingPivot($member->id, ['role' => $validated['role']]);
        AuditLog::record($request->user(), 'member.role', $patient->id, null, ['user' => $member->email, 'from' => $member->pivot->role, 'to' => $validated['role']]);

        return response()->json(['message' => 'Role updated']);
    }

    /**
     * Remove a member (owners), or leave (anyone, for themselves). Takes effect on the member's
     * next request: access, alerts and pushes all check membership at that moment.
     */
    public function removeMember(Request $request, $id, $userId)
    {
        $me = $request->user();
        $leaving = (int) $userId === (int) $me->id;
        $patient = $this->patientById($request, $id, $leaving ? 'view' : 'manage');
        $member = $patient->users()->where('users.id', $userId)->first() ?? abort(404);

        if ($member->pivot->role === Patient::OWNER && $patient->ownerCount() <= 1) {
            return response()->json(['message' => 'A child needs at least one owner. Delete the child instead, or make someone else owner first.'], 422);
        }
        $patient->users()->detach($member->id);
        AuditLog::record($me, $leaving ? 'member.left' : 'member.removed', $patient->id, null, ['user' => $member->email]);

        return response()->json(['message' => $leaving ? 'You left' : 'Access removed']);
    }

    /** Switch my own cough alerts for this child on or off. */
    public function setAlerts(Request $request, $id)
    {
        $patient = $this->patientById($request, $id);
        $validated = $request->validate(['alerts' => 'required|boolean']);
        $patient->users()->updateExistingPivot($request->user()->id, ['alerts' => $validated['alerts']]);

        return response()->json(['alerts' => $validated['alerts']]);
    }

    /** Recent audit entries for a child (owners only). */
    public function audit(Request $request, $id)
    {
        $patient = $this->patientById($request, $id, 'manage');

        $entries = AuditLog::with('user:id,name')
            ->where('patient_id', $patient->id)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn (AuditLog $a) => [
                'action' => $a->action,
                'by' => $a->user?->name,
                'details' => $a->details,
                'created_at' => $a->created_at,
            ]);

        return response()->json(['entries' => $entries]);
    }

    private function patientById(Request $request, $id, string $ability = 'view'): Patient
    {
        $request->merge(['patient_id' => $id]);

        return $this->patient($request, $ability);
    }

    private function emailMatches(User $user, PatientInvite $invite): bool
    {
        return strtolower(trim($user->email)) === $invite->email;
    }
}
