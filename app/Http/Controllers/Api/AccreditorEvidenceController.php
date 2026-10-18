<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\MyAreaResource;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Services\AuditLogService;
use App\Notifications\EvidenceReleasedToAccreditorNotification;
use App\Support\ActiveCycle;
use App\Support\RoleSlug;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;

class AccreditorEvidenceController extends Controller
{
    public function showSubmission(Request $request)
    {
        [$program, $cycle] = $this->chairContext($request->user());

        return response()->json([
            'success' => true,
            'message' => 'Accreditor submission status retrieved.',
            'data' => $this->submissionPayload($program, $cycle),
        ]);
    }

    public function submit(Request $request)
    {
        $user = $request->user();
        [$program, $cycle] = $this->chairContext($user);

        $alreadySubmitted = $cycle->submitted_to_accreditor_at !== null;

        if (! $alreadySubmitted) {
            $cycle->forceFill([
                'submitted_to_accreditor_at' => now(),
                'submitted_to_accreditor_by' => $user->id,
            ])->save();

            $cycle->load('program.college');
            $accreditors = User::role(RoleSlug::ACCREDITOR)->get();
            if ($accreditors->isNotEmpty()) {
                Notification::send($accreditors, new EvidenceReleasedToAccreditorNotification($cycle));
            }

            app(AuditLogService::class)->record($request, 'accreditor_evidence_submitted');
        }

        $cycle->refresh()->load('submittedToAccreditorBy');

        return response()->json([
            'success' => true,
            'message' => $alreadySubmitted
                ? 'This accreditation was already submitted to the accreditor.'
                : 'Evidence submitted to the accreditor.',
            'data' => $this->submissionPayload($program, $cycle),
        ], $alreadySubmitted ? 200 : 201);
    }

    public function unsubmit(Request $request)
    {
        $user = $request->user();
        [$program, $cycle] = $this->chairContext($user);

        if ($cycle->submitted_to_accreditor_at === null) {
            return response()->json([
                'success' => true,
                'message' => 'This accreditation is not currently submitted to the accreditor.',
                'data' => $this->submissionPayload($program, $cycle),
            ]);
        }

        $cycle->forceFill([
            'submitted_to_accreditor_at' => null,
            'submitted_to_accreditor_by' => null,
        ])->save();

        app(AuditLogService::class)->record($request, 'accreditor_evidence_unsubmitted');

        $cycle->refresh()->load('submittedToAccreditorBy');

        return response()->json([
            'success' => true,
            'message' => 'Submission withdrawn. The accreditor can no longer view this program\'s evidence.',
            'data' => $this->submissionPayload($program, $cycle),
        ]);
    }

    public function colleges(Request $request)
    {
        $this->assertAccreditor($request->user());

        $cycles = AccreditationCycle::query()
            ->with('program.college')
            ->whereNotNull('submitted_to_accreditor_at')
            ->get();

        $colleges = $cycles
            ->map(function (AccreditationCycle $cycle) {
                $college = $cycle->program?->college;

                if (! $college && $cycle->college_id) {
                    $college = College::query()->find($cycle->college_id);
                }

                return $college;
            })
            ->filter()
            ->unique('id')
            ->sortBy(fn (College $college) => $college->name)
            ->values()
            ->map(function (College $college) use ($cycles) {
                $programCount = $cycles
                    ->filter(function (AccreditationCycle $cycle) use ($college) {
                        $collegeId = $cycle->program?->college_id ?: $cycle->college_id;

                        return (int) $collegeId === (int) $college->id;
                    })
                    ->pluck('program_id')
                    ->unique()
                    ->count();

                return [
                    'id' => $college->id,
                    'name' => $college->name,
                    'code' => $college->code,
                    'submittedProgramCount' => $programCount,
                ];
            })
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Submitted colleges retrieved.',
            'data' => $colleges,
        ]);
    }

    public function programs(Request $request, College $college)
    {
        $this->assertAccreditor($request->user());

        $cycles = AccreditationCycle::query()
            ->with('program')
            ->whereNotNull('submitted_to_accreditor_at')
            ->where(function ($query) use ($college) {
                $query->where('college_id', $college->id)
                    ->orWhereHas('program', fn ($program) => $program->where('college_id', $college->id));
            })
            ->orderByDesc('submitted_to_accreditor_at')
            ->get();

        $programs = $cycles
            ->groupBy('program_id')
            ->map(function ($group) {
                /** @var AccreditationCycle $latest */
                $latest = $group->sortByDesc(fn (AccreditationCycle $cycle) => $cycle->submitted_to_accreditor_at?->getTimestamp() ?? 0)->first();
                $program = $latest->program;

                if (! $program) {
                    return null;
                }

                return [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                    'levels' => $group->pluck('level')->filter()->unique()->values()->all(),
                    'submittedAt' => $latest->submitted_to_accreditor_at?->toDateTimeString(),
                ];
            })
            ->filter()
            ->sortBy('name')
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Submitted programs retrieved.',
            'data' => $programs,
        ]);
    }

    public function areas(Request $request, Program $program)
    {
        $this->assertAccreditor($request->user());

        $releasedCycleIds = AccreditationCycle::query()
            ->where('program_id', $program->id)
            ->whereNotNull('submitted_to_accreditor_at')
            ->pluck('id');

        if ($releasedCycleIds->isEmpty()) {
            abort(403, 'The program chair has not submitted this accreditation to the accreditor yet.');
        }

        $areas = AccreditationArea::with(['chair', 'cycle.program', 'members.user', 'reviews'])
            ->whereIn('cycle_id', $releasedCycleIds)
            ->whereNotNull('code')
            ->orderBy('code')
            ->orderBy('id')
            ->get();

        $areas = ActiveCycle::uniqueAreasPerProgram($areas);

        return response()->json([
            'success' => true,
            'message' => 'Submitted accreditation areas retrieved.',
            'data' => MyAreaResource::collection($areas),
        ]);
    }

    /**
     * @return array{0: Program, 1: AccreditationCycle}
     */
    private function chairContext(?User $user): array
    {
        if (! $user || ! $user->isProgramChair()) {
            abort(403, 'Only a program chair can submit evidence to the accreditor.');
        }

        $program = $user->chairedProgram() ?: $user->assignedProgram();
        if (! $program) {
            abort(422, 'You are not assigned to a program.');
        }

        $program->loadMissing('activeCycle');
        $cycle = $program->activeCycle;
        if (! $cycle) {
            abort(422, 'Set the program\'s active accreditation level before submitting to the accreditor.');
        }

        $cycle->loadMissing('submittedToAccreditorBy');

        return [$program, $cycle];
    }

    private function assertAccreditor(?User $user): void
    {
        if (! $user || ! $user->isAccreditor()) {
            abort(403, 'Only accreditors can view submitted evidence.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function submissionPayload(Program $program, AccreditationCycle $cycle): array
    {
        $program->loadMissing('college');
        $submitter = $cycle->submittedToAccreditorBy;

        return [
            'programId' => $program->id,
            'programName' => $program->name,
            'collegeName' => $program->college?->name,
            'cycleId' => $cycle->id,
            'level' => $cycle->level,
            'submitted' => $cycle->submitted_to_accreditor_at !== null,
            'submittedAt' => $cycle->submitted_to_accreditor_at?->toDateTimeString(),
            'submittedBy' => $submitter ? [
                'id' => $submitter->id,
                'name' => $submitter->name,
            ] : null,
        ];
    }
}
