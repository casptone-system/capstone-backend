<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Program;
use App\Models\Team;
use App\Models\User;
use App\Support\RoleSlug;
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

        if (! $user || ! $user->isSuperAdmin()) {
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

    private function canonicalRoleFromValidated(array $validated): ?string
    {
        return RoleSlug::canonicalize($validated['role'] ?? null);
    }

    private function ensureDeanHasValidCollege(array $validated): ?\Illuminate\Http\JsonResponse
    {
        if ($this->canonicalRoleFromValidated($validated) !== RoleSlug::DEAN) {
            return null;
        }

        $collegeId = $validated['college_id'] ?? null;
        if (! $collegeId) {
            return response()->json([
                'success' => false,
                'message' => 'A dean must be assigned to a college.',
                'errors' => ['college_id' => ['A dean must belong to a college.']],
            ], 422);
        }

        return null;
    }

    private function ensureProgramChairHasProgram(array $validated): ?\Illuminate\Http\JsonResponse
    {
        if ($this->canonicalRoleFromValidated($validated) !== RoleSlug::PROGRAM_CHAIR) {
            return null;
        }

        if (empty($validated['program_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'A program must be selected when creating a Program Chair.',
                'errors' => ['program_id' => ['A program is required for the Program Chair role.']],
            ], 422);
        }

        $program = Program::find($validated['program_id']);
        if ($program?->chair_id) {
            return response()->json([
                'success' => false,
                'message' => 'This program already has a Program Chair.',
                'errors' => ['program_id' => ['This program already has a Program Chair.']],
            ], 422);
        }

        return null;
    }

    private function ensureChairIsNotAlreadyAssigned(?int $programId, ?User $user = null): ?\Illuminate\Http\JsonResponse
    {
        if (! $user || ! $programId) {
            return null;
        }

        $existing = Program::where('chair_id', $user->id)->where('id', '!=', $programId)->first();
        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This user already chairs a different program.',
                'errors' => ['program_id' => ['A Program Chair may only be assigned to one program.']],
            ], 422);
        }

        return null;
    }

    private function ensureNoDuplicateDeanForCollege(?int $collegeId, ?User $user = null): ?\Illuminate\Http\JsonResponse
    {
        if (! $collegeId) {
            return null;
        }

        $query = User::query()
            ->where('college_id', $collegeId)
            ->whereHas('roles', fn ($roleQuery) => $roleQuery->where('name', RoleSlug::DEAN));

        if ($user) {
            $query->whereKeyNot($user->id);
        }

        if ($query->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'This college already has an active Dean.',
                'errors' => ['college_id' => ['This college already has an active Dean.']],
            ], 422);
        }

        return null;
    }

    private function applyRoleOrgConstraints(User $user, string $slug, array $validated): void
    {
        if (RoleSlug::isInstitutionWide($slug)) {
            $user->college_id = null;
            $user->program_id = null;
            $user->team_id = null;

            return;
        }

        if ($slug === RoleSlug::DEAN) {
            $user->college_id = $validated['college_id'] ?? $user->college_id;
            $user->program_id = null;
            $user->team_id = null;
        }
    }

    private function createWelcomeTaskForUser(User $user, User $admin): void
    {
        try {
            $role = $user->roles->first()?->name ?? 'faculty';
            
            $title = match (strtolower($role)) {
                'faculty' => 'Complete Your Faculty Profile',
                'program chair' => 'Program Chair Onboarding Setup',
                'dean' => 'Dean Dashboard Overview',
                'area in-charge' => 'Area In-Charge Setup',
                'area-in-charge' => 'Area In-Charge Setup',
                'qa' => 'QA Dashboard Orientation',
                'vpaa' => 'VPAA Portal Setup',
                'vpaa/di' => 'VPAA Portal Setup',
                'accreditor' => 'Accreditor Access Guide',
                'super administrator' => 'Administrator Portal Overview',
                'super admin' => 'Administrator Portal Overview',
                'superadmin' => 'Administrator Portal Overview',
                default => 'Welcome to the System',
            };

            $description = match (strtolower($role)) {
                'faculty' => "Welcome {$user->first_name}! Please complete your faculty profile with your contact information and qualifications. This helps us keep your records up to date.",
                
                'program chair' => "Welcome {$user->first_name}! As a Program Chair, please review the accreditation dashboard, familiarize yourself with the accreditation structure for your program, and contact your dean if you have any questions.",
                
                'dean' => "Welcome {$user->first_name}! Your dean dashboard is now ready. Please review the college structure, connect with your program chairs, and set up your college profile to get started.",
                
                'area in-charge', 'area-in-charge' => "Welcome {$user->first_name}! You are now assigned as an Area In-Charge. Please review the accreditation areas assigned to you and familiarize yourself with the review process.",
                
                'qa' => "Welcome {$user->first_name}! As a Quality Assurance coordinator, you now have access to monitor all accreditation activities. Please review the QA dashboard to get started.",
                
                'vpaa', 'vpaa/di' => "Welcome {$user->first_name}! As VPAA, you have full oversight of all accreditation cycles. Please review the academic administration portal and set up accreditation parameters.",
                
                'accreditor' => "Welcome {$user->first_name}! You now have access to review accreditation submissions. Please review the accreditor guide and familiarize yourself with the evaluation process.",
                
                'super administrator', 'super admin', 'superadmin' => "Welcome {$user->first_name}! As a System Administrator, you have full access to all system features. Please review the administration portal for configuration options.",
                
                default => "Welcome {$user->first_name}! Your account has been created successfully. Please explore the system and familiarize yourself with the available features.",
            };

            \App\Models\TaskNotification::create([
                'assigned_by_id' => $admin->id,
                'assigned_to_id' => $user->id,
                'title' => $title,
                'description' => $description,
                'type' => 'onboarding',
                'is_welcome_task' => true,
                'badge_clear_hours' => 72, // 3 days for welcome tasks
                'status' => 'pending',
            ]);
        } catch (\Exception $e) {
            // Log but don't fail the user creation if task creation fails
            \Log::warning("Failed to create welcome task for user {$user->id}: " . $e->getMessage());
        }
    }

    private function ensureRoleHasPermissions(Role $role, string $roleNameLower): void
    {
        // Define default permissions for each role
        $rolePermissions = [
            'faculty' => [
                'view dashboard',
                'upload documents',
                'submit reviews',
            ],
            'dean' => [
                'view dashboard',
                'access-college-dashboard',
                'approve reviews',
                'review reports',
            ],
            'program chair' => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'request revisions',
            ],
            'program-chair' => [
                'view dashboard',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'review reports',
                'manage reviews',
                'request revisions',
            ],
            'area in-charge' => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            'area-in-charge' => [
                'view dashboard',
                'manage reviews',
                'request revisions',
                'review reports',
            ],
            'qa' => [
                'view dashboard',
                'review reports',
                'view audit logs',
            ],
            'vpaa' => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            'vpaa/di' => [
                'view dashboard',
                'approve reviews',
                'review reports',
                'view audit logs',
            ],
            'super administrator' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
            'super admin' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
            'superadmin' => [
                'view dashboard',
                'manage users',
                'manage teams',
                'invite faculty',
                'assign chairs',
                'manage documents',
                'submit reviews',
                'manage reviews',
                'approve reviews',
                'request revisions',
                'review reports',
                'view audit logs',
                'view login history',
            ],
        ];

        $permissionNames = $rolePermissions[$roleNameLower] ?? $rolePermissions['faculty'];

        // Only assign permissions if the role doesn't already have any
        // This prevents overwriting manually assigned permissions
        $existingPermissions = $role->permissions()->pluck('name')->toArray();
        
        foreach ($permissionNames as $permissionName) {
            if (!in_array($permissionName, $existingPermissions)) {
                $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
                $role->givePermissionTo($permission);
            }
        }
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
        $user = $request->user();

        if (! $actor && (! $user || (! $user->isDean() && ! $user->isProgramChair()))) {
            return response()->json(['success' => false, 'message' => 'You do not have permission to view users.'], 403);
        }

        $query = User::query()->with(['program', 'team']);

        if ($user && $user->isDean()) {
            $collegeId = $user->college_id;
            if ($collegeId) {
                $query->where(function ($q) use ($collegeId) {
                    $q->where('college_id', $collegeId)
                        ->orWhereIn('program_id', Program::where('college_id', $collegeId)->pluck('id'));
                });
            } else {
                $query->whereRaw('0 = 1');
            }
        }

        if ($user && $user->isProgramChair()) {
            $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
            if (! $programId) {
                $query->whereRaw('0 = 1');
            } else {
                $query->where('program_id', $programId);
            }
        }

        $users = $query->latest()->paginate($request->get('per_page', 20));

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
            'send_welcome_task' => ['nullable', 'boolean'],
            'additional_tasks' => ['nullable', 'array'],
            'additional_tasks.*.title' => ['string', 'max:255'],
            'additional_tasks.*.description' => ['nullable', 'string'],
            'additional_tasks.*.type' => ['in:document_upload,review,approval,assignment,other,onboarding'],
        ]);

        $slug = RoleSlug::canonicalize($validated['role'] ?? null);
        if (! $slug) {
            return response()->json([
                'success' => false,
                'message' => 'The selected role is invalid.',
                'errors' => ['role' => ['Use a canonical role slug.']],
            ], 422);
        }
        $validated['role'] = $slug;

        if ($response = $this->ensureDeanHasValidCollege($validated)) {
            return $response;
        }

        if ($response = $this->ensureProgramChairHasProgram($validated)) {
            return $response;
        }

        $collegeId = $slug === RoleSlug::DEAN ? ($validated['college_id'] ?? null) : null;
        if ($slug === RoleSlug::DEAN && ($response = $this->ensureNoDuplicateDeanForCollege($collegeId))) {
            return $response;
        }

        if ($slug === RoleSlug::PROGRAM_CHAIR) {
            $chairCandidate = null;
            if ($response = $this->ensureChairIsNotAlreadyAssigned((int) $validated['program_id'], $chairCandidate)) {
                return $response;
            }
        }

        $user = null;

        \Illuminate\Support\Facades\DB::transaction(function () use (&$user, $validated, $actor, $request) {
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

            $slug = RoleSlug::canonicalize($validated['role']) ?? RoleSlug::FACULTY;
            $this->applyRoleOrgConstraints($user, $slug, $validated);
            $user->save();

            $role = Role::firstOrCreate(['name' => $slug, 'guard_name' => 'web']);
            $user->assignRole($role);
            $this->ensureRoleHasPermissions($role, $slug);

            if ($slug === RoleSlug::PROGRAM_CHAIR && ! empty($validated['program_id'])) {
                $program = Program::find($validated['program_id']);
                if ($program && ! $program->chair_id) {
                    $program->update([
                        'chair_id' => $user->id,
                        'chair' => $user->name,
                    ]);
                    $user->program_id = $program->id;
                    $user->college_id = $program->college_id;
                    $user->save();
                }
            }

            if ($slug === RoleSlug::DEAN) {
                $college = $user->college;
                if ($college) {
                    $user->notify(new \App\Notifications\DeanAssignedNotification($college, $actor, $user));
                }
            }

            // Create welcome task if requested
            if ($validated['send_welcome_task'] ?? true) {
                $this->createWelcomeTaskForUser($user, $actor);
            }

            // Create additional tasks if provided
            if (!empty($validated['additional_tasks'])) {
                foreach ($validated['additional_tasks'] as $taskData) {
                    \App\Models\TaskNotification::create([
                        'assigned_by_id' => $actor->id,
                        'assigned_to_id' => $user->id,
                        'title' => $taskData['title'],
                        'description' => $taskData['description'] ?? null,
                        'type' => $taskData['type'] ?? 'assignment',
                        'status' => 'pending',
                    ]);
                }
            }

            AuditLog::create([
                'user_id' => $actor->id,
                'user_email' => $actor->email,
                'event' => 'USER_CREATED',
                'method' => 'POST',
                'path' => '/api/admin/users',
                'status' => 'success',
                'ip_address' => $request->ip(),
            ]);
        });

        if (! $user) {
            return response()->json(['success' => false, 'message' => 'User creation failed.'], 500);
        }

        $user->refresh();

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

        $chairs = User::role(RoleSlug::PROGRAM_CHAIR)
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

    public function areaInCharges(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $user->isProgramChair()) {
            return response()->json(['success' => false, 'message' => 'Only a Program Chair can view Area In-Charges.'], 403);
        }

        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
        $members = User::role(RoleSlug::AREA_IN_CHARGE)
            ->where('program_id', $programId)
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'email', 'program_id'])
            ->map(fn ($member) => [
                'id' => $member->id,
                'name' => $member->name ?: trim(sprintf('%s %s %s', $member->first_name, $member->middle_name ?? '', $member->last_name)),
                'email' => $member->email,
                'program_id' => $member->program_id,
            ]);

        return response()->json(['success' => true, 'data' => $members]);
    }

    public function programFaculty(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only a Program Chair can view program faculty.');
        }

        $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
        if (! $programId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $faculty = User::query()
            ->where('users.program_id', $programId)
            ->whereHas('roles', function ($roleQuery) {
                $roleQuery->whereIn('name', [RoleSlug::FACULTY, RoleSlug::AREA_IN_CHARGE]);
            })
            ->with('roles')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'profile_photo', 'program_id']);

        $payload = $faculty->map(fn (User $person) => [
            'id' => $person->id,
            'name' => $person->name,
            'email' => $person->email,
            'photo' => $person->profile_photo_url,
            'profilePhoto' => $person->profile_photo_url,
            'role' => $person->roles->pluck('name')->first() ?: 'Faculty',
        ])->values();

        if (! $payload->contains(fn ($person) => (int) $person['id'] === (int) $user->id)) {
            $payload = $payload->prepend([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'photo' => $user->profile_photo_url,
                'profilePhoto' => $user->profile_photo_url,
                'role' => 'Program Chair',
            ])->values();
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * Search users by name or email for the searchable dropdown used in
     * the Faculty Area Assignments module (Program Chair UI).
     */
    public function search(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! ($user->isProgramChair() || $user->isDean() || $user->isVPAA() || $user->isSuperAdmin())) {
            abort(403, 'You are not authorized to search users.');
        }

        $q = trim((string) $request->input('q', ''));
        $query = User::with('roles')->whereNull('users.deleted_at');

        if ($user->isProgramChair()) {
            $programId = $user->assignedProgramId() ?: $user->getEffectiveProgramId();
            if (! $programId) {
                return response()->json(['success' => true, 'data' => []]);
            }
            $query->where('users.program_id', $programId);
        } elseif ($user->isDean()) {
            $collegeId = $user->college_id;
            if (! $collegeId) {
                return response()->json(['success' => true, 'data' => []]);
            }
            $query->where(function ($scoped) use ($collegeId) {
                $scoped->where('users.college_id', $collegeId)
                    ->orWhereIn('users.program_id', Program::where('college_id', $collegeId)->pluck('id'));
            });
        }

        if ($q !== '') {
            $query->where(function ($where) use ($q) {
                $where->where('users.name', 'like', "%{$q}%")
                    ->orWhere('users.email', 'like', "%{$q}%")
                    ->orWhere('users.first_name', 'like', "%{$q}%")
                    ->orWhere('users.last_name', 'like', "%{$q}%");
            });
        }

        $results = $query
            ->orderBy('users.last_name')
            ->orderBy('users.first_name')
            ->limit((int) $request->input('limit', 25))
            ->get(['id', 'name', 'first_name', 'middle_name', 'last_name', 'email', 'profile_photo']);

        return response()->json([
            'success' => true,
            'data' => $results->map(fn (User $person) => [
                'id' => $person->id,
                'name' => $person->name,
                'email' => $person->email,
                'photo' => $person->profile_photo_url,
                'profilePhoto' => $person->profile_photo_url,
                'role' => $person->roles->pluck('name')->first() ?: RoleSlug::FACULTY,
            ])->values(),
        ]);
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
        $requestedCollegeId = $validated['college_id'] ?? null;
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
            $slug = RoleSlug::canonicalize($validated['role']);
            if (! $slug) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected role is invalid.',
                    'errors' => ['role' => ['Use a canonical role slug.']],
                ], 422);
            }

            $role = Role::firstOrCreate(['name' => $slug, 'guard_name' => 'web']);

            if ($slug === RoleSlug::DEAN) {
                $collegeId = $requestedCollegeId ?? $user->college_id;
                if (! $collegeId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A dean must be assigned to a college.',
                        'errors' => ['college_id' => ['A dean must belong to a college.']],
                    ], 422);
                }
                if ($response = $this->ensureNoDuplicateDeanForCollege($collegeId, $user)) {
                    return $response;
                }
            }

            if ($slug === RoleSlug::PROGRAM_CHAIR) {
                $programId = $validated['program_id'] ?? $user->program_id;
                if (! $programId) {
                    return response()->json([
                        'success' => false,
                        'message' => 'A program must be selected for a Program Chair.',
                        'errors' => ['program_id' => ['A program is required for the Program Chair role.']],
                    ], 422);
                }
                if ($response = $this->ensureChairIsNotAlreadyAssigned((int) $programId, $user)) {
                    return $response;
                }
            }

            $user->syncRoles([$role->name]);
            $this->applyRoleOrgConstraints($user, $slug, $validated);
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
