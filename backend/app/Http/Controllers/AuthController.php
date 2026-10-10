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

        // No child is created here: someone who only signs up to accept an invitation must not get
        // an empty child of their own. A parent's first child is created when they pair a device or
        // add one (Children & rooms).

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

    /**
     * Set the account photo: a data URL of a JPEG, PNG or WebP image the dashboard has already
     * cropped and resized to 256 px (which also drops the photo's location data). The image is
     * checked for real here; SVG is refused because it can carry scripts.
     */
    public function updateAvatar(Request $request)
    {
        $request->validate(['avatar' => 'required|string|max:' . (int) ceil(User::AVATAR_MAX_BYTES * 4 / 3 + 32)]);

        $invalid = fn () => response()->json(['message' => 'Choose a JPEG, PNG or WebP photo.'], 422);
        if (!preg_match('#^data:image/(jpeg|png|webp);base64,([A-Za-z0-9+/]+={0,2})$#', $request->avatar, $m)) {
            return $invalid();
        }
        $bytes = base64_decode($m[2], true);
        if ($bytes === false || strlen($bytes) > User::AVATAR_MAX_BYTES) {
            return $invalid();
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info['mime'] !== "image/{$m[1]}" || $info[0] < 1 || $info[1] < 1
            || $info[0] > User::AVATAR_MAX_SIZE || $info[1] > User::AVATAR_MAX_SIZE) {
            return $invalid();
        }

        $user = $request->user();
        $user->forceFill(['avatar' => $request->avatar])->save();

        return response()->json(['avatar' => $user->avatar]);
    }

    public function deleteAvatar(Request $request)
    {
        $request->user()->forceFill(['avatar' => null])->save();

        return response()->json(['avatar' => null]);
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
