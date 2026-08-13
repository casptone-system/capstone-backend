<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Program;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    protected function ensureSuperAdmin(Request $request): ?User
    {
        $user = $request->user('api') ?? $request->user();

        if (! $user || ! (
            $user->hasRole('Super Administrator') ||
            $user->hasRole('Super Admin') ||
            $user->hasRole('super administrator') ||
            $user->hasRole('superadmin')
        )) {
            return null;
        }

        return $user;
    }

    private function resolveCollegeIdFromValidatedUserData(array $validated): ?int
    {
        if (! empty($validated['college_id'])) {
            return $validated['college_id'];
        }

        if (! empty($validated['program_id'])) {
            return Program::find($validated['program_id'])?->college_id;
        }

        if (! empty($validated['team_id'])) {
            return Team::find($validated['team_id'])?->program?->college_id;
        }

        return null;
    }

    private function ensureDeanHasValidCollege(array $validated): ?\Illuminate\Http\JsonResponse
    {
        if (strtolower($validated['role']) !== 'dean') {
            return null;
        }

        $collegeId = $this->resolveCollegeIdFromValidatedUserData($validated);
        if (! $collegeId) {
            return response()->json([
                'success' => false,
                'message' => 'A dean must belong to a college via a college, program, or team association.',
                'errors' => ['college_id' => ['A dean must belong to a college.']],
            ], 422);
        }

        if (! empty($validated['college_id']) && ! empty($validated['program_id'])) {
            $programCollegeId = Program::find($validated['program_id'])?->college_id;
            if ($programCollegeId && $programCollegeId !== $validated['college_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected program belongs to a different college than the dean.',
                    'errors' => ['program_id' => ['The selected program belongs to a different college.']],
                ], 422);
            }
        }

        if (! empty($validated['college_id']) && ! empty($validated['team_id'])) {
            $teamCollegeId = Team::find($validated['team_id'])?->program?->college_id;
            if ($teamCollegeId && $teamCollegeId !== $validated['college_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected team belongs to a different college than the dean.',
                    'errors' => ['team_id' => ['The selected team belongs to a different college.']],
                ], 422);
            }
        }

        return null;
    }

    public function dashboard(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view the admin dashboard.'], 403);
        }

        return response()->json([
            'success' => true,
            'message' => 'Admin dashboard retrieved successfully.',
            'data' => [
                'summary' => [
                    'totalUsers' => User::count(),
                    'totalPrograms' => Program::count(),
                    'totalAreas' => \App\Models\AccreditationArea::count(),
                    'totalEvidence' => \App\Models\Document::count(),
                    'pendingReviews' => \App\Models\Review::whereNotIn('current_status', ['Ready', 'Rejected'])->count(),
                ],
            ],
        ], 200);
    }

    public function index(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view users.'], 403);
        }

        $users = User::query()
            ->with(['program', 'team'])
            ->latest()
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => [
                'users' => UserResource::collection($users),
                'meta' => [
                    'current_page' => $users->currentPage(),
                    'last_page' => $users->lastPage(),
                    'per_page' => $users->perPage(),
                    'total' => $users->total(),
                ],
            ],
        ]);
    }

    public function store(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to create users.'], 403);
        }

        $validated = $request->validate([
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'phone' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'role' => ['required', 'string'],
            'college_id' => ['nullable', 'integer', 'exists:colleges,id'],
            'program_id' => ['nullable', 'integer', 'exists:programs,id'],
            'team_id' => ['nullable', 'integer', 'exists:teams,id'],
            'status' => ['nullable', 'string'],
        ]);

        if ($response = $this->ensureDeanHasValidCollege($validated)) {
            return $response;
        }

        $user = User::create([
            'first_name' => $validated['first_name'],
            'last_name' => $validated['last_name'],
            'middle_name' => $validated['middle_name'] ?? null,
            'name' => trim(sprintf('%s %s %s', $validated['first_name'], $validated['middle_name'] ?? '', $validated['last_name'])),
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'phone_number' => $validated['phone'] ?? null,
            'birth_date' => $validated['birthdate'] ?? null,
            'college_id' => $validated['college_id'] ?? null,
            'program_id' => $validated['program_id'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
        ]);

        $roleName = str_replace('_', ' ', $validated['role']);
        $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user->assignRole($role);

        AuditLog::create([
            'user_id' => $actor->id,
            'user_email' => $actor->email,
            'event' => 'USER_CREATED',
            'method' => 'POST',
            'path' => '/api/admin/users',
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'message' => 'User created successfully.', 'data' => new UserResource($user)], 201);
    }

    public function show(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view this user.'], 403);
        }

        $user = User::with(['program', 'team'])->findOrFail($id);

        return response()->json(['success' => true, 'data' => new UserResource($user)]);
    }

    public function programChairs(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || (! $user->isSuperAdmin() && ! $user->isDean())) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view program chairs.'], 403);
        }

        $chairs = User::role('Program Chair')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'first_name', 'middle_name', 'last_name', 'email', 'program_id'])
            ->map(fn ($chair) => [
                'id' => $chair->id,
                'name' => trim(sprintf('%s %s %s', $chair->first_name, $chair->middle_name ?? '', $chair->last_name)),
                'email' => $chair->email,
                'programId' => $chair->program_id,
            ]);

        return response()->json(['success' => true, 'data' => $chairs]);
    }

    public function update(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to edit users.'], 403);
        }

        $user = User::findOrFail($id);

        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'middle_name' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:255'],
            'birthdate' => ['nullable', 'date'],
            'role' => ['nullable', 'string'],
            'college_id' => ['nullable', 'integer', 'exists:colleges,id'],
            'program_id' => ['nullable', 'integer'],
            'team_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);

        $before = $user->toArray();
        if (isset($validated['first_name'])) $user->first_name = $validated['first_name'];
        if (isset($validated['last_name'])) $user->last_name = $validated['last_name'];
        if (isset($validated['middle_name'])) $user->middle_name = $validated['middle_name'];
        if (isset($validated['email'])) $user->email = $validated['email'];
        if (isset($validated['phone'])) $user->phone_number = $validated['phone'];
        if (isset($validated['birthdate'])) $user->birth_date = $validated['birthdate'];
        if (array_key_exists('college_id', $validated)) $user->college_id = $validated['college_id'];
        if (array_key_exists('program_id', $validated)) $user->program_id = $validated['program_id'];
        if (array_key_exists('team_id', $validated)) $user->team_id = $validated['team_id'];
        $wasDean = $user->isDean();
        $newRoleName = null;
        if (isset($validated['role'])) {
            $roleName = str_replace('_', ' ', $validated['role']);
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $user->syncRoles([$role->name]);
            $newRoleName = $role->name;
        }
        $user->save();

        $updatedCollege = null;
        if (array_key_exists('college_id', $validated) && ! is_null($validated['college_id'])) {
            $updatedCollege = College::find($validated['college_id']);
        }

        $isNowDean = $user->fresh()->isDean();
        if ($isNowDean && ! $wasDean) {
            try {
                $targetCollege = $updatedCollege ?? $user->fresh()->college;
                if ($targetCollege) {
                    $user->notify(new \App\Notifications\DeanAssignedNotification($targetCollege, $actor, $user));

                    if ($actor && $actor->id !== $user->id) {
                        $actor->notify(new \App\Notifications\DeanAssignedNotification($targetCollege, $actor, $user));
                    }
                }
            } catch (\Throwable $e) {
                // Ignore notification failures so that the user update still succeeds.
            }
        }

        AuditLog::create([
            'user_id' => $actor->id,
            'user_email' => $actor->email,
            'event' => 'USER_UPDATED',
            'method' => 'PUT',
            'path' => "/api/admin/users/{$id}",
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'message' => 'User updated successfully.', 'data' => new UserResource($user)]);
    }

    public function destroy(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to delete users.'], 403);
        }

        $user = User::findOrFail($id);
        if ($user->id === $actor->id) {
            return response()->json(['success' => false, 'message' => 'You cannot delete your own account.'], 403);
        }

        $user->delete();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_email' => $actor->email,
            'event' => 'USER_DELETED',
            'method' => 'DELETE',
            'path' => "/api/admin/users/{$id}",
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'message' => 'User deleted successfully.']);
    }

    public function restore(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to restore users.'], 403);
        }

        $user = User::withTrashed()->findOrFail($id);
        $user->restore();
        AuditLog::create([
            'user_id' => $actor->id,
            'user_email' => $actor->email,
            'event' => 'USER_RESTORED',
            'method' => 'POST',
            'path' => "/api/admin/users/{$id}/restore",
            'status' => 'success',
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['success' => true, 'message' => 'User restored successfully.']);
    }

    public function lock(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to lock users.'], 403);

        $user = User::findOrFail($id);
        $user->forceFill(['email_verified_at' => now()])->save();
        AuditLog::create(['user_id' => $actor->id, 'user_email' => $actor->email, 'event' => 'ACCOUNT_LOCKED', 'method' => 'POST', 'path' => "/api/admin/users/{$id}/lock", 'status' => 'success', 'ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'message' => 'User locked successfully.']);
    }

    public function unlock(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to unlock users.'], 403);

        $user = User::findOrFail($id);
        AuditLog::create(['user_id' => $actor->id, 'user_email' => $actor->email, 'event' => 'ACCOUNT_UNLOCKED', 'method' => 'POST', 'path' => "/api/admin/users/{$id}/unlock", 'status' => 'success', 'ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'message' => 'User unlocked successfully.']);
    }

    public function activate(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to activate users.'], 403);

        $user = User::findOrFail($id);
        AuditLog::create(['user_id' => $actor->id, 'user_email' => $actor->email, 'event' => 'ACCOUNT_ACTIVATED', 'method' => 'POST', 'path' => "/api/admin/users/{$id}/activate", 'status' => 'success', 'ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'message' => 'User activated successfully.']);
    }

    public function deactivate(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to deactivate users.'], 403);

        $user = User::findOrFail($id);
        AuditLog::create(['user_id' => $actor->id, 'user_email' => $actor->email, 'event' => 'ACCOUNT_DEACTIVATED', 'method' => 'POST', 'path' => "/api/admin/users/{$id}/deactivate", 'status' => 'success', 'ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'message' => 'User deactivated successfully.']);
    }

    public function resetPassword(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to reset passwords.'], 403);

        $user = User::findOrFail($id);
        $newPassword = $request->input('password') ?: substr(md5((string) now()->timestamp . $user->email), 0, 12);
        $user->password = Hash::make($newPassword);
        $user->save();
        AuditLog::create(['user_id' => $actor->id, 'user_email' => $actor->email, 'event' => 'PASSWORD_RESET_COMPLETED', 'method' => 'POST', 'path' => "/api/admin/users/{$id}/reset-password", 'status' => 'success', 'ip_address' => $request->ip()]);

        return response()->json(['success' => true, 'message' => 'Password reset successfully.']);
    }

    public function roles(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to view roles.'], 403);

        return response()->json(['success' => true, 'data' => Role::all()]);
    }

    public function storeRole(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to create roles.'], 403);

        $validated = $request->validate(['name' => ['required', 'string', 'max:255'], 'guard_name' => ['nullable', 'string']]);
        $role = Role::firstOrCreate(['name' => $validated['name'], 'guard_name' => $validated['guard_name'] ?? 'web']);

        return response()->json(['success' => true, 'message' => 'Role created successfully.', 'data' => $role]);
    }

    public function permissions(Request $request)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to view permissions.'], 403);

        return response()->json(['success' => true, 'data' => Permission::all()]);
    }

    public function rolePermissions(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to view role permissions.'], 403);

        $role = Role::findOrFail($id);

        return response()->json(['success' => true, 'data' => [
            'id' => $role->id,
            'name' => $role->name,
            'permissions' => $role->getPermissionNames(),
        ]]);
    }

    public function assignPermissions(Request $request, string $id)
    {
        $actor = $this->ensureSuperAdmin($request);
        if (! $actor) return response()->json(['success' => false, 'message' => 'You do not have permission to assign permissions.'], 403);

        $role = Role::findOrFail($id);
        $permissions = $request->input('permissions', []);
        $role->syncPermissions($permissions);

        return response()->json(['success' => true, 'message' => 'Permissions assigned successfully.', 'data' => $role]);
    }
}
