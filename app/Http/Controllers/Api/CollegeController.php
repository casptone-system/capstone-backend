<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\CollegeResource;
use App\Models\College;
use App\Models\User;
use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class CollegeController extends Controller
{
    protected function ensureSuperAdmin(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user || ! $user->isSuperAdmin()) {
            return null;
        }

        return $user;
    }

    public function index(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view colleges.'], 403);
        }

        $colleges = College::with('programs')
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', "%{$request->search}%")->orWhere('code', 'like', "%{$request->search}%"))
            ->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Colleges retrieved successfully.',
            'data' => CollegeResource::collection($colleges),
        ], 200);
    }

    public function store(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create colleges.'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:colleges,code'],
            'description' => ['nullable', 'string'],
        ]);

        $deanPayload = $request->input('dean');

        $inviteFailure = null;
        $result = DB::transaction(function () use ($validated, $deanPayload, $actor, &$inviteFailure) {
            $college = College::create($validated);

            $createdDean = null;
            $invitation = null;

            if (is_array($deanPayload) && ! empty($deanPayload['email'])) {
                $email = $deanPayload['email'];
                $name = $deanPayload['name'] ?? null;
                $autoCreate = isset($deanPayload['auto_create']) ? (bool) $deanPayload['auto_create'] : false;

                if ($autoCreate) {
                    $password = Str::random(12);
                    $user = User::create([
                        'name' => $name ?? $email,
                        'email' => $email,
                        'password' => Hash::make($password),
                        'college_id' => $college->id,
                    ]);

                    // assign dean role if roles exist
                    try {
                        $user->assignRole('dean');
                    } catch (\Throwable $e) {
                        // ignore role assignment failures
                    }

                    // send verification/invite email if applicable
                    try {
                        $user->sendEmailVerificationNotification();
                    } catch (\Throwable $e) {
                        // best-effort
                    }

                    $createdDean = $user;
                } else {
                    $token = bin2hex(random_bytes(24));
                    try {
                        $invitation = Invitation::create([
                            'program_id' => null,
                            'team_id' => null,
                            'email' => $email,
                            'role' => 'dean',
                            'token' => $token,
                            'invited_by' => $actor->id,
                            'expires_at' => now()->addDays(3),
                            'status' => 'pending',
                        ]);
                    } catch (\Throwable $e) {
                        // mark failure so we can persist an audit log after the main transaction
                        $inviteFailure = $e->getMessage();
                        // don't throw — allow transaction to complete (invitation was not created)
                        $invitation = null;
                    }
                }
            }

            return ['college' => $college, 'dean' => $createdDean, 'invitation' => $invitation];
        });

        $college = $result['college'];

        $response = ['success' => true, 'message' => 'College created successfully.', 'data' => new CollegeResource($college)];
        if ($result['dean']) {
            $response['dean'] = ['id' => $result['dean']->id, 'email' => $result['dean']->email, 'name' => $result['dean']->name];
            \App\Models\AuditLog::create([
                'user_id' => $actor->id,
                'user_email' => $actor->email,
                'event' => 'CREATE_DEAN',
                'method' => 'POST',
                'path' => "api/colleges/{$college->id}",
                'status' => 'success',
                'ip_address' => request()->ip(),
            ]);
        }
        if ($result['invitation']) {
            $response['invitation'] = ['token' => $result['invitation']->token, 'email' => $result['invitation']->email];
            \App\Models\AuditLog::create([
                'user_id' => $actor->id,
                'user_email' => $actor->email,
                'event' => 'INVITE_DEAN',
                'method' => 'POST',
                'path' => "api/invitations/{$result['invitation']->token}",
                'status' => 'success',
                'ip_address' => request()->ip(),
            ]);
        }

        // If an invitation attempt failed earlier during the transaction, record an audit entry
        if (!empty($inviteFailure)) {
            try {
                \App\Models\AuditLog::create([
                    'user_id' => $actor->id,
                    'user_email' => $actor->email,
                    'event' => 'INVITE_DEAN_FAILED',
                    'method' => 'POST',
                    'path' => request()->path(),
                    'status' => 'error',
                    'ip_address' => request()->ip(),
                ]);
            } catch (\Throwable $_) {
            }
            $response['invitation_error'] = $inviteFailure;
        }

        return response()->json($response, 201);
    }

    public function show(Request $request, College $college)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view this college.'], 403);
        }

        $college->load('programs');

        return response()->json([
            'success' => true,
            'message' => 'College retrieved successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function update(Request $request, College $college)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to update this college.'], 403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:colleges,code,' . $college->id],
            'description' => ['nullable', 'string'],
        ]);

        $college->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'College updated successfully.',
            'data' => new CollegeResource($college),
        ], 200);
    }

    public function destroy(Request $request, College $college)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete this college.'], 403);
        }

        $college->delete();

        return response()->json([
            'success' => true,
            'message' => 'College deleted successfully.',
        ], 200);
    }
}
