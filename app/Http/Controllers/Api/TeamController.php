<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\TeamJoinCode;
use App\Models\User;
use App\Notifications\TeamCodeInviteNotification;
use App\Support\OrgScope;
use App\Support\TeamCodes;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        abort_unless($user, 401);

        $query = Team::with(['program', 'creator', 'joinCodes.usedBy'])
            ->orderByDesc('created_at');
        OrgScope::constrainPrograms($query, $user);

        if ($request->filled('program_id')) {
            abort_unless(OrgScope::canSeeProgram($user, (int) $request->program_id), 403, 'You are not allowed to view this program\'s teams.');
            $query->where('program_id', $request->program_id);
        }

        $teams = $query->get();
        $programMembers = $this->currentProgramMembers($teams->pluck('program_id')->filter()->unique()->all());

        $teams = $teams->map(function (Team $team) use ($programMembers): array {
            $members = $team->members()->get()->map(function ($member): array {
                return [
                    'id' => $member->id,
                    'name' => $this->personName($member),
                    'email' => $member->email,
                ];
            })->values();

            return [
                'id' => $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'program_id' => $team->program_id,
                'created_by' => $team->created_by,
                'expires_at' => $team->expires_at,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'program' => $team->program ? [
                    'id' => $team->program->id,
                    'name' => $team->program->name,
                    'code' => $team->program->code,
                ] : null,
                'members' => $members,
                'member_count' => $programMembers->get((string) $team->program_id, collect())->count(),
                'program_members' => $programMembers->get((string) $team->program_id, collect())->values(),
                'join_codes' => $team->joinCodes->map(fn (TeamJoinCode $joinCode): array => $this->joinCodePayload($joinCode))->values(),
                'area' => 'Unassigned',
                'status' => 'Active',
                'statusClass' => 'ts-active',
            ];
        })->values();

        return response()->json([
            'success' => true,
            'message' => 'Teams retrieved successfully.',
            'data' => $teams,
        ], 200);
    }

    public function store(Request $request)
    {
        $user = $request->user('api') ?? $request->user();

        $isSuperAdmin = $user && $user->isSuperAdmin();

        if (! $user || (! $user->isProgramChair() && ! $isSuperAdmin && ! $user->can('manage teams'))) {
            return response()->json(['success' => false, 'message' => 'Forbidden'], 403);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'program_id' => ['required', 'exists:programs,id'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $programId = (int) $validated['program_id'];
        abort_unless(OrgScope::canSeeProgram($user, $programId), 403, 'You are not allowed to create a team for this program.');
        if ($user->isProgramChair() && ! $user->ownsAssignedProgram($programId)) {
            abort(403, 'You can only create a team for the program you chair.');
        }

        $code = TeamCodes::unique();

        $team = Team::create([
            'name' => $validated['name'],
            'program_id' => $validated['program_id'],
            'code' => $code,
            'created_by' => $user->id,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        $joinCode = $this->storeJoinCode($team, $user, $code);

        $request->attributes->set('audit_event', 'team_code_created');

        return response()->json([
            'success' => true,
            'message' => 'Team created successfully.',
            'data' => [
                'id' => $team->id,
                'name' => $team->name,
                'code' => $joinCode->code,
                'join_code_id' => $joinCode->id,
                'program_id' => $team->program_id,
                'created_by' => $team->created_by,
                'expires_at' => $joinCode->expires_at,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'members' => [],
                'member_count' => 0,
                'program_members' => [],
                'join_codes' => [$this->joinCodePayload($joinCode)],
                'area' => 'Unassigned',
                'status' => 'Active',
                'statusClass' => 'ts-active',
            ],
        ], 201);
    }

    public function show(Request $request, Team $team)
    {
        $user = $request->user('api') ?? $request->user();
        abort_unless($user && OrgScope::canSeeProgram($user, (int) $team->program_id), 403, 'You are not allowed to view this team.');

        return response()->json([
            'success' => true,
            'message' => 'Team retrieved successfully.',
            'data' => [
                'id' => $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'program_id' => $team->program_id,
                'created_by' => $team->created_by,
                'expires_at' => $team->expires_at,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'program' => $team->program ? [
                    'id' => $team->program->id,
                    'name' => $team->program->name,
                    'code' => $team->program->code,
                ] : null,
                'members' => $team->members()->get()->map(function ($member) {
                    return [
                        'id' => $member->id,
                        'name' => trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')) ?: $member->name,
                        'email' => $member->email,
                    ];
                })->values(),
                'member_count' => $team->members()->count(),
                'area' => 'Unassigned',
                'status' => 'Active',
                'statusClass' => 'ts-active',
            ],
        ], 200);
    }

    public function regenerate(Request $request, Team $team)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $this->canManageCode($user, $team)) {
            return response()->json(['success' => false, 'message' => 'You cannot change this team code.'], 403);
        }

        $previousCode = $team->code;
        $nextCode = TeamCodes::unique();

        TeamJoinCode::query()
            ->where('team_id', $team->id)
            ->where('code', $previousCode)
            ->whereNull('used_by')
            ->update(['expires_at' => now()]);

        $this->storeJoinCode($team, $user, $nextCode);

        $team->forceFill([
            'code' => $nextCode,
            'expires_at' => null,
        ])->save();

        $request->attributes->set('audit_event', 'team_code_regenerated');

        return response()->json([
            'success' => true,
            'message' => 'Team code regenerated. The previous code no longer works.',
            'data' => $this->teamPayload($team->fresh()),
        ], 200);
    }

    public function revoke(Request $request, Team $team)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $this->canManageCode($user, $team)) {
            return response()->json(['success' => false, 'message' => 'You cannot revoke this team code.'], 403);
        }

        $team->forceFill(['expires_at' => now()])->save();
        TeamJoinCode::query()
            ->where('team_id', $team->id)
            ->where('code', $team->code)
            ->whereNull('used_by')
            ->update(['expires_at' => now()]);
        $request->attributes->set('audit_event', 'team_code_revoked');

        return response()->json([
            'success' => true,
            'message' => 'Team code revoked.',
            'data' => $this->teamPayload($team->fresh()),
        ], 200);
    }

    public function issueCode(Request $request, Team $team)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $this->canManageCode($user, $team)) {
            return response()->json(['success' => false, 'message' => 'You cannot create a team code.'], 403);
        }

        $joinCode = $this->storeJoinCode($team, $user);
        $team->forceFill([
            'code' => $joinCode->code,
            'expires_at' => null,
        ])->save();

        $request->attributes->set('audit_event', 'team_code_created');

        return response()->json([
            'success' => true,
            'message' => 'Team code generated.',
            'data' => $this->joinCodePayload($joinCode),
        ], 201);
    }

    public function revokeCode(Request $request, Team $team, TeamJoinCode $joinCode)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $this->canManageCode($user, $team)) {
            return response()->json(['success' => false, 'message' => 'You cannot revoke this team code.'], 403);
        }

        if ((int) $joinCode->team_id !== (int) $team->id) {
            abort(404);
        }

        if ($joinCode->isUsed()) {
            return response()->json([
                'success' => false,
                'message' => 'This team code has already been used.',
            ], 422);
        }

        $joinCode->forceFill(['expires_at' => now()])->save();
        if ($team->code === $joinCode->code) {
            $team->forceFill(['expires_at' => now()])->save();
        }

        $request->attributes->set('audit_event', 'team_code_revoked');

        return response()->json([
            'success' => true,
            'message' => 'Team code revoked.',
            'data' => $this->joinCodePayload($joinCode->fresh()),
        ], 200);
    }

    public function invite(Request $request, Team $team)
    {
        $user = $request->user('api') ?? $request->user();
        if (! $user || ! $this->canEmailCode($user, $team)) {
            return response()->json(['success' => false, 'message' => 'You cannot email this team code.'], 403);
        }

        if (! config('adams.team_invites_enabled')) {
            return response()->json([
                'success' => false,
                'message' => 'Email invitations are turned off. Copy the team code and send it yourself.',
            ], 409);
        }

        if ($team->expires_at && ! $team->expires_at->isFuture()) {
            return response()->json([
                'success' => false,
                'message' => 'This team code is revoked. Generate a new one before sending it.',
            ], 422);
        }

        $activeJoinCode = TeamJoinCode::query()
            ->where('team_id', $team->id)
            ->whereNull('used_by')
            ->where('expires_at', '>', now())
            ->orderByDesc('id')
            ->first();

        if (! $activeJoinCode) {
            return response()->json([
                'success' => false,
                'message' => 'This team code has expired. Generate a new one before sending it.',
            ], 422);
        }

        $codeToEmail = $activeJoinCode->code;

        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $limitKey = 'team_invite_email:' . $user->id;
        $sent = (int) Cache::get($limitKey, 0);
        if ($sent >= 10) {
            return response()->json([
                'success' => false,
                'message' => 'Too many invite emails. Try again later.',
            ], 429);
        }

        $programName = $team->program?->name ?? 'your program';

        try {
            \Illuminate\Support\Facades\Notification::route('mail', $validated['email'])
                ->notify(new TeamCodeInviteNotification($codeToEmail, $programName));
        } catch (\Throwable $exception) {
            Log::error('Team code invite email failed', [
                'team_id' => $team->id,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'We could not send the invite email. Try again in a few minutes.',
            ], 503);
        }

        Cache::put($limitKey, $sent + 1, 3600);
        $request->attributes->set('audit_event', 'team_code_emailed');

        return response()->json([
            'success' => true,
            'message' => 'Team code emailed.',
            'data' => [
                'email' => $validated['email'],
                'code' => $codeToEmail,
            ],
        ], 200);
    }

    private function canManageCode(User $user, Team $team): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        return $user->isProgramChair() && $user->ownsAssignedProgram((int) $team->program_id);
    }

    private function canEmailCode(User $user, Team $team): bool
    {
        if ($this->canManageCode($user, $team)) {
            return true;
        }

        $team->loadMissing('program');

        return $user->isDean()
            && $team->program
            && (int) $team->program->college_id === (int) $user->college_id;
    }

    private function teamPayload(Team $team): array
    {
        return [
            'id' => $team->id,
            'name' => $team->name,
            'code' => $team->code,
            'program_id' => $team->program_id,
            'expires_at' => $team->expires_at,
        ];
    }

    private function storeJoinCode(Team $team, User $user, ?string $code = null): TeamJoinCode
    {
        return TeamJoinCode::query()->create([
            'team_id' => $team->id,
            'program_id' => $team->program_id,
            'code' => $code ?: TeamCodes::unique(),
            'created_by' => $user->id,
            'expires_at' => now()->addMinutes(TeamJoinCode::TTL_MINUTES),
        ]);
    }

    private function joinCodePayload(TeamJoinCode $joinCode): array
    {
        $usedBy = $joinCode->relationLoaded('usedBy') ? $joinCode->usedBy : $joinCode->usedBy()->first();

        return [
            'id' => $joinCode->id,
            'code' => $joinCode->code,
            'team_id' => $joinCode->team_id,
            'program_id' => $joinCode->program_id,
            'expires_at' => $joinCode->expires_at,
            'created_at' => $joinCode->created_at,
            'used_at' => $joinCode->used_at,
            'used_by' => $usedBy ? [
                'id' => $usedBy->id,
                'name' => $this->personName($usedBy),
                'email' => $usedBy->email,
            ] : null,
            'status' => $joinCode->isUsed() ? 'used' : ($joinCode->isExpired() ? 'expired' : 'active'),
        ];
    }

    /**
     * @param  list<int|string>  $programIds
     */
    private function currentProgramMembers(array $programIds): \Illuminate\Support\Collection
    {
        if ($programIds === []) {
            return collect();
        }

        return User::query()
            ->whereIn('program_id', $programIds)
            ->with('roles')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (User $person): string => (string) $person->program_id)
            ->map(fn ($people) => $people->map(fn (User $person): array => [
                'id' => $person->id,
                'name' => $this->personName($person),
                'email' => $person->email,
                'role' => $this->roleLabel($person),
                'profilePhoto' => $person->profile_photo_url,
            ])->values());
    }

    private function personName(User $person): string
    {
        $fromParts = trim(($person->first_name ?? '').' '.($person->last_name ?? ''));

        return $fromParts !== '' ? $fromParts : (string) ($person->name ?: $person->email);
    }

    private function roleLabel(User $person): string
    {
        $name = $person->roles->pluck('name')->first();
        if (! $name) {
            return 'Member';
        }

        return ucwords(str_replace(['-', '_'], ' ', (string) $name));
    }
}
