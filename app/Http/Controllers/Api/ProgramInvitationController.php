<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Invitation;
use App\Models\Program;
use App\Models\ProgramMember;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class ProgramInvitationController extends Controller
{
    public function index(Program $program)
    {
        $this->authorize('viewAny', [Invitation::class, $program]);

        $invitations = Invitation::where('program_id', $program->id)->get();

        return response()->json(['success' => true, 'data' => $invitations], 200);
    }

    public function store(Request $request, Program $program)
    {
        $user = $request->user();

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $this->authorize('create', [Invitation::class, $program]);

        $key = 'invitation-create:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['success' => false, 'message' => 'Too many invitation creation attempts.'], 429);
        }
        RateLimiter::hit($key, 60);

        $validated = $request->validate([
            'email' => ['nullable', 'email'],
            'role' => ['nullable', 'string'],
            'expires_in_hours' => ['nullable', 'integer', 'min:1', 'max:168'],
        ]);

        $token = bin2hex(random_bytes(24));

        $inv = Invitation::create([
            'program_id' => $program->id,
            'email' => $validated['email'] ?? null,
            'role' => $validated['role'] ?? null,
            'token' => $token,
            'invited_by' => $user->id,
            'expires_at' => isset($validated['expires_in_hours']) ? now()->addHours($validated['expires_in_hours']) : now()->addDays(3),
            'status' => 'pending',
        ]);

        return response()->json(['success' => true, 'data' => $inv], 201);
    }

    public function resend(Request $request, string $token)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $inv = Invitation::where('token', $token)->first();
        if (! $inv) {
            return response()->json(['success' => false, 'message' => 'Invalid invitation.'], 404);
        }

        $this->authorize('resend', $inv);

        $key = 'invitation-resend:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return response()->json(['success' => false, 'message' => 'Too many resend attempts.'], 429);
        }
        RateLimiter::hit($key, 60);

        $inv->expires_at = now()->addDays(3);
        $inv->status = 'pending';
        $inv->save();

        return response()->json(['success' => true, 'data' => $inv], 200);
    }

    public function revoke(Request $request, string $token)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $inv = Invitation::where('token', $token)->first();
        if (! $inv) {
            return response()->json(['success' => false, 'message' => 'Invalid invitation.'], 404);
        }

        $this->authorize('revoke', $inv);

        $inv->status = 'revoked';
        $inv->save();

        return response()->json(['success' => true, 'data' => $inv], 200);
    }

    public function accept(Request $request, string $token)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Authentication required.'], 401);
        }

        $inv = Invitation::where('token', $token)->first();
        if (! $inv) {
            return response()->json(['success' => false, 'message' => 'Invalid invitation.'], 404);
        }

        if ($inv->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Invitation is no longer valid.'], 400);
        }

        if ($inv->expires_at && now()->greaterThan($inv->expires_at)) {
            $inv->status = 'expired';
            $inv->save();

            return response()->json(['success' => false, 'message' => 'Invitation has expired.'], 400);
        }

        $this->authorize('accept', $inv);

        $key = 'invitation-accept:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json(['success' => false, 'message' => 'Too many acceptance attempts.'], 429);
        }
        RateLimiter::hit($key, 60);

        $exists = ProgramMember::where('program_id', $inv->program_id)
            ->where('user_id', $user->id)
            ->exists();

        if ($exists) {
            $inv->status = 'accepted';
            $inv->used_by = $user->id;
            $inv->accepted_at = now();
            $inv->save();

            return response()->json(['success' => true, 'message' => 'Already a member.'], 200);
        }

        ProgramMember::create([
            'program_id' => $inv->program_id,
            'user_id' => $user->id,
            'role' => $inv->role ?? 'member',
            'joined_at' => now(),
            'invited_by' => $inv->invited_by,
        ]);

        $inv->status = 'accepted';
        $inv->used_by = $user->id;
        $inv->accepted_at = now();
        $inv->save();

        return response()->json(['success' => true, 'message' => 'Invitation accepted.'], 200);
    }
}
