<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProgramResource;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\Team;
use App\Models\User;
use App\Support\OrgScope;
use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class ProgramController extends Controller
{
    /**
     * Display a paginated list of programs.
     */
    public function index(Request $request)
    {
        $this->authorize('viewAny', Program::class);

        $query = Program::with(['college', 'chairUser']);
        OrgScope::constrainPrograms($query, $request->user());

        if ($request->filled('college_id')) {
            $query->where('college_id', $request->college_id);
        }

        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $programs = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Programs retrieved successfully.',
            'data' => ProgramResource::collection($programs),
        ], 200);
    }

    /**
     * Store a newly created program.
     */
    public function store(Request $request)
    {
        $this->authorize('create', Program::class);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:50', 'unique:programs,code'],
            'chair' => ['nullable', 'string', 'max:255'],
            'chair_id' => ['nullable', 'integer', 'exists:users,id'],
            'chair_name' => ['nullable', 'string', 'max:255'],
            'chair_email' => ['nullable', 'email', 'max:255'],
            'profile_photo' => ['nullable', 'image', 'max:10240'],
            'accreditation_status' => ['nullable', 'in:compliant,at-risk,non-compliant'],
            'compliance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
        ];

        if ($request->user()?->isDean()) {
            $rules['college_id'] = ['sometimes', 'exists:colleges,id'];
        } else {
            $rules['college_id'] = ['required', 'exists:colleges,id'];
        }

        $validated = $request->validate($rules);

        if (empty($validated['code'])) {
            $validated['code'] = $this->generateUniqueProgramCode($validated['name'] ?? null);
        }

        if ($request->user()?->isDean() && $request->has('college_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Deans may not specify a college when creating a program.',
                'errors' => ['college_id' => ['College may not be specified by a dean.']],
            ], 403);
        }

        if ($request->user()?->isDean()) {
            $collegeId = $request->user()->college_id;
            if (! $collegeId) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unable to determine the dean’s college.',
                    'errors' => ['college_id' => ['The dean does not belong to a college.']],
                ], 422);
            }

            $validated['college_id'] = $collegeId;
        }

        $chairUser = null;
        $chairId = $validated['chair_id'] ?? null;
        $hasNewChairData = ! empty($validated['chair_name']) && ! empty($validated['chair_email']);

        if (! empty($chairId)) {
            $chairUser = User::find($chairId);
            if (! $chairUser || ! $this->userHasProgramChairRole($chairUser)) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected program chair is invalid.',
                    'errors' => ['chair_id' => ['The selected program chair is invalid.']],
                ], 422);
            }

            if ($response = $this->rejectIfChairAlreadyAssigned($chairUser, null)) {
                return $response;
            }

            $chairCollegeId = $chairUser->college_id;
            if ($chairCollegeId && $chairCollegeId !== $validated['college_id']) {
                return response()->json([
                    'success' => false,
                    'message' => 'The selected program chair must belong to the same college as the program.',
                    'errors' => ['chair_id' => ['The selected program chair belongs to a different college.']],
                ], 422);
            }

            if (empty($validated['chair'])) {
                $validated['chair'] = $chairUser->name;
            }
        }

        if (! empty($validated['chair_name']) && empty($validated['chair_email'])) {
            return response()->json([
                'success' => false,
                'message' => 'Program Chair email is required when creating a new chair.',
                'errors' => ['chair_email' => ['Program Chair email is required.']],
            ], 422);
        }

        if (! empty($validated['chair_email']) && empty($validated['chair_name'])) {
            return response()->json([
                'success' => false,
                'message' => 'Program Chair name is required when creating a new chair.',
                'errors' => ['chair_name' => ['Program Chair name is required.']],
            ], 422);
        }

        if ($chairId === null && ! $hasNewChairData && ! array_key_exists('chair', $validated)) {
            $validated['chair'] = null;
        }

        $team = null;
        $program = DB::transaction(function () use ($validated, $request, $chairUser, $hasNewChairData, &$team) {
            $programPayload = $validated;
            // profile_photo is intended for the Program Chair user, not the programs table
            unset($programPayload['chair_name'], $programPayload['chair_email'], $programPayload['profile_photo']);

            $program = Program::create($programPayload);

            $team = Team::create([
                'name' => $program->name . ' Team',
                'program_id' => $program->id,
                'code' => $this->generateUniqueTeamCode(),
                'created_by' => $request->user()->id,
            ]);

            if ($chairUser) {
                $program->chair_id = $chairUser->id;
                if (empty($program->chair)) {
                    $program->chair = $chairUser->name;
                }
                $program->save();

                $this->assignProgramChairToProgram($chairUser, $program, $team);
                $this->ensureProgramChairMembership($chairUser, $program, $request->user());
            } elseif ($hasNewChairData) {
                // handle profile photo if uploaded
                $profilePhotoPath = null;
                if ($request->hasFile('profile_photo')) {
                    $profilePhotoPath = $request->file('profile_photo')->store('profile_photos', 'public');
                }

                $chairUser = $this->createProgramChairUser([
                    'chair_name' => $validated['chair_name'],
                    'chair_email' => $validated['chair_email'],
                    'profile_photo_path' => $profilePhotoPath,
                ], $program, $team);

                $program->chair_id = $chairUser->id;
                $program->chair = $chairUser->name;
                $program->save();

                $this->ensureProgramChairMembership($chairUser, $program, $request->user());

                // notify the newly created Program Chair
                try {
                    $chairUser->notify(new \App\Notifications\ProgramChairAssignedNotification($program, $request->user()));
                } catch (\Exception $e) {
                    // ignore notification failures
                }
            }

            return $program;
        });

        // ensure relations are loaded for response
        $program->load(['college', 'chairUser']);

        return response()->json([
            'success' => true,
            'message' => 'Program created successfully.',
            'data' => new ProgramResource($program),
            'team' => $team ? [
                'id' => $team->id,
                'name' => $team->name,
                'code' => $team->code,
                'program_id' => $team->program_id,
                'created_by' => $team->created_by,
                'expires_at' => $team->expires_at,
            ] : null,
        ], 201);
    }

    /**
     * Display the specified program.
     */
    public function show(Program $program)
    {
        $this->authorize('view', $program);

        $program->load(['college', 'chairUser']);

        return response()->json([
            'success' => true,
            'message' => 'Program retrieved successfully.',
            'data' => new ProgramResource($program),
        ], 200);
    }

    private function userHasProgramChairRole(User $user): bool
    {
        return $user->isProgramChair() || $user->isFaculty();
    }

    private function rejectIfChairAlreadyAssigned(User $chairUser, ?int $programId): ?\Illuminate\Http\JsonResponse
    {
        $existing = Program::where('chair_id', $chairUser->id)
            ->when($programId, fn ($q) => $q->where('id', '!=', $programId))
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'This user already chairs a different program.',
                'errors' => ['chair_id' => ['A Program Chair may only be assigned to one program.']],
            ], 422);
        }

        return null;
    }

    public function removeMember(Program $program, User $user)
    {
        $actor = request()->user();
        abort_unless(
            $actor && (
                $actor->isSuperAdmin()
                || ($actor->isDean() && (int) $program->college_id === (int) $actor->college_id)
                || ($actor->isProgramChair() && $actor->ownsAssignedProgram($program->id))
            ),
            403,
            'You are not allowed to remove members from this program.'
        );

        $member = ProgramMember::where('program_id', $program->id)
            ->where('user_id', $user->id)
            ->first();
        $belongs = $user->belongsToProgram($program->id);

        if (! $member && ! $belongs) {
            return response()->json([
                'success' => false,
                'message' => 'This faculty member is not assigned to the program.',
            ], 404);
        }

        $member?->delete();

        if ($belongs) {
            $user->program_id = null;
            $user->save();
        }

        return response()->json([
            'success' => true,
            'message' => 'Faculty member removed from the program.',
        ], 200);
    }

    /**
     * Update the specified program.
     */
    public function update(Request $request, Program $program)
    {
        $this->authorize('update', $program);

        $rules = [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'code' => ['sometimes', 'required', 'string', 'max:50', 'unique:programs,code,' . $program->id],
            'chair' => ['nullable', 'string', 'max:255'],
            'chair_id' => ['nullable', 'integer', 'exists:users,id'],
            'accreditation_status' => ['nullable', 'in:compliant,at-risk,non-compliant'],
            'compliance_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'accreditation_level' => ['nullable', 'string', 'max:255'],
            'accreditation_phase' => ['nullable', 'string', 'max:255'],
            'scheduled_visit' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date'],
        ];

        if ($request->user()?->isDean()) {
            $rules['college_id'] = ['sometimes', 'exists:colleges,id'];
        } else {
            $rules['college_id'] = ['sometimes', 'required', 'exists:colleges,id'];
        }

        $validated = $request->validate($rules);

        $actor = $request->user();
        if (! $actor?->isVPAA() && ! $actor?->isSuperAdmin()) {
            unset($validated['scheduled_visit'], $validated['valid_until']);
        }

        if ($request->user()?->isDean() && $request->has('college_id')) {
            return response()->json([
                'success' => false,
                'message' => 'Deans may not specify a college when updating a program.',
                'errors' => ['college_id' => ['College may not be specified by a dean.']],
            ], 403);
        }

        if (array_key_exists('chair_id', $validated)) {
            $chairId = $validated['chair_id'] ?? null;
            if (! empty($chairId)) {
                $chairUser = User::find($chairId);
                if (! $chairUser || ! $this->userHasProgramChairRole($chairUser)) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected program chair is invalid.',
                        'errors' => ['chair_id' => ['The selected program chair is invalid.']],
                    ], 422);
                }

                if ($response = $this->rejectIfChairAlreadyAssigned($chairUser, $program->id)) {
                    return $response;
                }

                if (empty($validated['chair'])) {
                    $validated['chair'] = $chairUser->name;
                }
            }

            if ($chairId === null && ! array_key_exists('chair', $validated)) {
                $validated['chair'] = null;
            }
        }

        $oldChairId = $program->chair_id;
        $oldChair = $program->chairUser;
        $newChair = null;

        if (array_key_exists('chair_id', $validated) && ! empty($validated['chair_id'])) {
            $newChair = User::find($validated['chair_id']);
            if ($newChair) {
                $chairCollegeId = $newChair->college_id;
                if ($chairCollegeId && $chairCollegeId !== $program->college_id) {
                    return response()->json([
                        'success' => false,
                        'message' => 'The selected program chair must belong to the same college as the program.',
                        'errors' => ['chair_id' => ['The selected program chair belongs to a different college.']],
                    ], 422);
                }
            }
        }

        DB::transaction(function () use ($validated, $program, $oldChair, $newChair, $request) {
            $program->update($validated);

            if ($oldChair && (! $newChair || $oldChair->id !== $newChair->id)) {
                $this->detachPreviousChairFromProgram($oldChair, $program);
            }

            if ($newChair) {
                $this->assignProgramChairToProgram($newChair, $program);
                $this->ensureProgramChairMembership($newChair, $program, $request->user());
            }
        });

        // If there was a handover (oldChair -> newChair), record audit and notify
        if (isset($oldChair) && isset($newChair) && $oldChair->id !== $newChair->id) {
            \App\Models\AuditLog::create([
                'user_id' => $request->user()->id,
                'user_email' => $request->user()->email,
                'event' => 'PROGRAM_CHAIR_HANDOVER',
                'method' => 'PUT',
                'path' => "/api/programs/{$program->id}",
                'status' => 'success',
                'ip_address' => $request->ip(),
            ]);

            try {
                $oldChair->notify(new \App\Notifications\ProgramChairHandoverNotification($program, $oldChair, $newChair, $request->user(), false));
            } catch (\Exception $e) {
                // swallow notification errors
            }

            try {
                $newChair->notify(new \App\Notifications\ProgramChairHandoverNotification($program, $oldChair, $newChair, $request->user(), true));
            } catch (\Exception $e) {
            }
        }

        $program->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Program updated successfully.',
            'data' => new ProgramResource($program),
        ], 200);
    }

    private function createProgramChairUser(array $data, Program $program, Team $team): User
    {
        $name = trim($data['chair_name']);
        $email = strtolower(trim($data['chair_email']));
        $password = Str::random(12);

        $parts = preg_split('/\s+/', $name, -1, PREG_SPLIT_NO_EMPTY);
        $firstName = $parts[0] ?? $name;
        $lastName = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';

        $role = Role::firstOrCreate(['name' => RoleSlug::PROGRAM_CHAIR, 'guard_name' => 'web']);

        $userData = [
            'name' => $name,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'program_id' => $program->id,
            'team_id' => $team->id,
            'college_id' => $program->college_id,
        ];

        if (! empty($data['profile_photo_path'])) {
            $userData['profile_photo'] = $data['profile_photo_path'];
        }

        $user = User::create($userData);

        $user->assignRole($role);

        // send verification / invitation email
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Exception $e) {
            // If email sending fails, clean up and bubble up
            if (! empty($data['profile_photo_path'])) {
                \Illuminate\Support\Facades\Storage::disk('public')->delete($data['profile_photo_path']);
            }
            throw $e;
        }

        return $user;
    }

    private function generateUniqueProgramCode(?string $name = null): string
    {
        $base = 'PRG';

        if (! empty($name)) {
            $words = preg_split('/\s+/', trim($name), -1, PREG_SPLIT_NO_EMPTY);
            $letters = '';
            foreach (array_slice($words, 0, 2) as $word) {
                $clean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($word));
                if ($clean !== '') {
                    $letters .= substr($clean, 0, 1);
                }
            }
            if ($letters !== '') {
                $base = substr($letters, 0, 3);
            }
        }

        $index = 1;
        do {
            $code = sprintf('%s-%03d', strtoupper($base), $index);
            $index++;
        } while (Program::where('code', $code)->exists());

        return $code;
    }

    private function generateUniqueTeamCode(): string
    {
        do {
            $code = strtoupper(Str::random(6));
        } while (Team::where('code', $code)->exists());

        return $code;
    }

    private function assignProgramChairToProgram(User $chairUser, Program $program, Team $team = null): void
    {
        $updated = false;

        if ($chairUser->program_id !== $program->id) {
            $chairUser->program_id = $program->id;
            $updated = true;
        }

        if ($team && $chairUser->team_id !== $team->id) {
            $chairUser->team_id = $team->id;
            $updated = true;
        }

        if ($chairUser->college_id !== $program->college_id) {
            $chairUser->college_id = $program->college_id;
            $updated = true;
        }

        if (! $chairUser->isProgramChair()) {
            $chairUser->assignRole(RoleSlug::PROGRAM_CHAIR);
        }

        if ($updated) {
            $chairUser->save();
        }
    }

    private function ensureProgramChairMembership(User $chairUser, Program $program, User $assignedBy): void
    {
        $member = ProgramMember::where('program_id', $program->id)->where('user_id', $chairUser->id)->first();

        if (! $member) {
            ProgramMember::create([
                'program_id' => $program->id,
                'user_id' => $chairUser->id,
                'role' => 'program-chair',
                'joined_at' => now(),
                'invited_by' => $assignedBy->id,
            ]);
            return;
        }

        $member->role = 'program-chair';
        $member->joined_at = $member->joined_at ?? now();
        $member->invited_by = $member->invited_by ?? $assignedBy->id;
        $member->save();
    }

    private function detachPreviousChairFromProgram(User $previousChair, Program $program): void
    {
        $shouldSave = false;

        if ($previousChair->program_id === $program->id) {
            $previousChair->program_id = null;
            $shouldSave = true;
        }

        if ($previousChair->team_id && $previousChair->team?->program_id === $program->id) {
            $previousChair->team_id = null;
            $shouldSave = true;
        }

        if ($shouldSave) {
            $previousChair->save();
        }
    }

    /**
     * Remove the specified program.
     */
    public function destroy(Program $program)
    {
        $this->authorize('delete', $program);

        $program->delete();

        return response()->json([
            'success' => true,
            'message' => 'Program deleted successfully.',
        ], 200);
    }
}
