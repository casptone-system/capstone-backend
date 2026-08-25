<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Support\OrgScope;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user('api') ?? $request->user();
        abort_unless($user, 401);

        $query = Team::with(['program', 'creator'])
            ->orderByDesc('created_at');
        OrgScope::constrainPrograms($query, $user);

        if ($request->filled('program_id')) {
            abort_unless(OrgScope::canSeeProgram($user, (int) $request->program_id), 403, 'You are not allowed to view this program\'s teams.');
            $query->where('program_id', $request->program_id);
        }

        $teams = $query->get()->map(function (Team $team): array {
            $members = $team->members()->get()->map(function ($member): array {
                return [
                    'id' => $member->id,
                    'name' => trim(($member->first_name ?? '') . ' ' . ($member->last_name ?? '')) ?: $member->name,
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
                'member_count' => $members->count(),
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

        do {
            $code = strtoupper(Str::random(6));
        } while (Team::where('code', $code)->exists());

        $team = Team::create([
            'name' => $validated['name'],
            'program_id' => $validated['program_id'],
            'code' => $code,
            'created_by' => $user->id,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Team created successfully.',
            'data' => [
                'id' => $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'program_id' => $team->program_id,
                'created_by' => $team->created_by,
                'expires_at' => $team->expires_at,
                'created_at' => $team->created_at,
                'updated_at' => $team->updated_at,
                'members' => [],
                'member_count' => 0,
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
}
