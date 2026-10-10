<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Models\Device;
use App\Models\HardwareConfig;
use App\Models\LimitChange;
use App\Models\Patient;
use App\Models\User;
use App\Support\AiEngine;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        // Every account starts with one patient ("My child", renamed in the dashboard).
        $user->defaultPatient();

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ], 201);
    }
    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }

    public function updatePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'Current password is incorrect'
            ], 400);
        }

        $user->password = Hash::make($request->new_password);
        $user->save();

        return response()->json([
            'message' => 'Password updated successfully'
        ]);
    }

    public function deleteAccount(Request $request)
    {
        $user = $request->user();

        // PDPA right to erasure for the children this account owns (DESIGN section 3, ownership):
        //  - another owner exists: the child stays with them (only this membership goes);
        //  - shared with caregivers/viewers only: deleting would remove the child for them too, so
        //    it needs delete_shared_children=true (or make one of them owner first);
        //  - not shared: deleted with their dose history.
        $owned = $user->ownedPatients()->withCount('users')->get();
        $sharedWithoutOtherOwner = $owned->filter(fn ($p) => $p->users_count > 1 && $p->ownerCount() <= 1);
        if ($sharedWithoutOtherOwner->isNotEmpty() && !$request->boolean('delete_shared_children')) {
            return response()->json([
                'message' => 'These children are shared with others and have no other owner: '
                    . $sharedWithoutOtherOwner->pluck('name')->join(', ')
                    . '. Make someone else owner first, or confirm deleting them for everyone.',
                'shared_children' => $sharedWithoutOtherOwner->pluck('name')->values(),
            ], 409);
        }
        $deletedPatients = [];
        foreach ($owned as $patient) {
            if ($patient->ownerCount() <= 1) {
                $deletedPatients[] = $patient->id;
                $patient->delete();
                continue;
            }
            // The child stays with another owner, and so do the rooms this account paired for them.
            $heir = $patient->users()->wherePivot('role', Patient::OWNER)->where('users.id', '!=', $user->id)->orderBy('users.id')->first();
            $deviceIds = $user->devices()->where('patient_id', $patient->id)->pluck('id');
            Device::whereIn('id', $deviceIds)->update(['user_id' => $heir->id]);
            // Their limits and limit history too (both would otherwise go with this user, cascade).
            HardwareConfig::whereIn('device_id', $deviceIds)->update(['user_id' => $heir->id]);
            LimitChange::whereIn('device_id', $deviceIds)->update(['user_id' => $heir->id]);
        }

        // Devices, telemetry, coughs, configs and limit changes go with the user (cascade), and the
        // AI's model files of those rooms and children too.
        $deletedDevices = $user->devices()->pluck('id');
        $user->tokens()->delete();
        $user->delete();
        app(AiEngine::class)->forget($deletedDevices, $deletedPatients);

        return response()->json([
            'message' => 'Account permanently deleted in compliance with PDPA'
        ]);
    }
}
