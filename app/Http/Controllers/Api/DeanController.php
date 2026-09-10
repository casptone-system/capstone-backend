<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use App\Services\DeanDashboardService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;

class DeanController extends Controller
{
    public function __construct(private DeanDashboardService $deanDashboard)
    {
    }

    public function dashboard(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if (! $user->can('access-college-dashboard')) {
            return response()->json(['success' => false, 'message' => 'Dean is not assigned to a valid college.'], 403);
        }

        try {
            $data = $this->deanDashboard->dashboard(
                $user,
                $request->query('college_id') ? (int) $request->query('college_id') : null
            );
        } catch (ModelNotFoundException) {
            return response()->json(['success' => false, 'message' => 'College not found.'], 404);
        }

        return response()->json(['success' => true, 'data' => $data]);
    }

    public function programs(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        if (! $user->can('monitor-college', $this->deanDashboard->resolveCollege($user))) {
            return response()->json(['success' => false, 'message' => 'Dean is not permitted to access this college.'], 403);
        }

        $college = $this->deanDashboard->resolveCollege($user);
        if (! $college) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = Program::where('college_id', $college->id)->with(['college', 'activeCycle', 'accreditationCycles']);
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $programs = $query->paginate($request->get('per_page', 15));

        $programs->getCollection()->transform(function (Program $program) {
            $analytics = $this->deanDashboard->requirementAnalytics($program);

            return [
                'id' => $program->id,
                'college_id' => $program->college_id,
                'name' => $program->name,
                'code' => $program->code,
                'chair' => $program->chairUser?->name,
                'needsChairAssigned' => $program->needs_chair_assigned,
                'chair_id' => $program->chair_id,
                'accreditation_status' => $program->accreditation_status,
                'compliance_score' => (int) $program->compliance_score,
                'requirementProgress' => [
                    'totalTasks' => $analytics['totalTasks'],
                    'completedTasks' => $analytics['completedTasks'],
                    'inProgressTasks' => $analytics['inProgressTasks'],
                    'overdueTasks' => $analytics['overdueTasks'],
                    'completionRate' => $analytics['completionRate'],
                ],
                'areaProgress' => $this->deanDashboard->areaProgress($program),
                'requirements' => $analytics['requirements'],
                'created_at' => $program->created_at,
                'updated_at' => $program->updated_at,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Dean programs retrieved successfully.',
            'data' => $programs,
        ]);
    }

    public function documents(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $college = $this->deanDashboard->resolveCollege($user, $request->query('college_id') ? (int) $request->query('college_id') : null);
        if (! $college) {
            return response()->json(['success' => true, 'data' => ['data' => []]]);
        }

        $query = Document::with(['program', 'area', 'task', 'uploader', 'versions.uploader'])
            ->whereHas('program', fn ($q) => $q->where('college_id', $college->id));

        if ($request->filled('program_id')) {
            $query->where('program_id', $request->program_id);
        }

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('task_id')) {
            $query->where('task_id', $request->task_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = trim($request->search);
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhereHas('program', fn ($programQuery) => $programQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('code', 'like', "%{$search}%"))
                    ->orWhereHas('uploader', fn ($userQuery) => $userQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%"));
            });
        }

        $documents = $query->orderByDesc('created_at')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Dean documents retrieved successfully.',
            'data' => $documents,
        ]);
    }

    public function reviewQueue(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $college = $this->deanDashboard->resolveCollege($user);
        if (! $college) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $programIds = Program::where('college_id', $college->id)->pluck('id');

        $reviews = Review::with(['cycle.program', 'area'])
            ->whereIn('cycle_id', function ($query) use ($programIds) {
                $query->select('id')
                    ->from('accreditation_cycles')
                    ->whereIn('program_id', $programIds);
            })
            ->whereIn('current_status', ['Submitted', 'Area Approved', 'Revision Requested'])
            ->orderBy('updated_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Dean review queue retrieved successfully.',
            'data' => $reviews->map(function (Review $review) {
                $eligible = match ($review->current_status) {
                    'Submitted' => false,
                    'Area Approved' => true,
                    'Revision Requested' => false,
                    default => false,
                };

                return [
                    'id' => $review->id,
                    'program' => $review->cycle?->program?->name,
                    'area' => $review->area?->name,
                    'current_status' => $review->current_status,
                    'eligible_for_dean_action' => $eligible,
                    'can_approve' => false,
                    'can_return' => false,
                    'can_comment' => $eligible,
                    'review_url' => '/reviews/' . $review->id,
                ];
            })->values(),
        ]);
    }

    /**
     * Send notification to Program Chair about accreditation tasks/instruments
     */
    public function notifyProgramChair(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'program_chair_id' => 'required|exists:users,id',
            'program_name' => 'required|string|max:255',
            'instrument_file' => 'required|file|mimes:jpeg,png,gif,pdf,doc,docx|max:5120',
            'academic_year' => 'required|string|max:10',
            'description' => 'nullable|string|max:500',
        ]);

        $programChair = User::findOrFail($validated['program_chair_id']);

        // Verify program chair has permission
        if (! $programChair->isProgramChair()) {
            return response()->json(['success' => false, 'message' => 'User is not a Program Chair.'], 422);
        }

        // Verify college access
        $college = $this->deanDashboard->resolveCollege($user);
        if (! $college || ($programChair->college_id && (int) $programChair->college_id !== (int) $college->id)) {
            return response()->json(['success' => false, 'message' => 'Program Chair not in your college.'], 403);
        }

        try {
            // Store the instrument file
            $instrumentFile = $request->file('instrument_file');
            $fileName = 'instrument_' . time() . '_' . $instrumentFile->getClientOriginalName();
            $filePath = $instrumentFile->storeAs('dean-instruments', $fileName, 'private');

            // Send database notification
            $programChair->notify(new \App\Notifications\DeanTaskAssignmentNotification([
                'dean_name' => $user->name,
                'program_name' => $validated['program_name'],
                'instrument_file_path' => $filePath,
                'instrument_file_name' => $instrumentFile->getClientOriginalName(),
                'academic_year' => $validated['academic_year'],
                'description' => $validated['description'],
            ]));

            // Log activity
                \App\Models\AuditLog::create([
                    'user_id' => $user->id,
                    'event' => 'notify_program_chair',
                    'path' => 'dean/notify-program-chair',
                    'model' => 'Program Chair Notification',
                    'model_id' => $programChair->id,
                    'details' => [
                        'program' => $validated['program_name'],
                        'recipient' => $programChair->name,
                        'instrument_file' => $instrumentFile->getClientOriginalName(),
                    ],
                ]);

            return response()->json([
                'success' => true,
                'message' => 'Notification sent to Program Chair successfully.',
                'data' => [
                    'recipient_id' => $programChair->id,
                    'recipient_name' => $programChair->name,
                    'program_name' => $validated['program_name'],
                    'instrument_file' => $instrumentFile->getClientOriginalName(),
                    'sent_at' => now()->toIso8601String(),
                ],
            ], 200);
                }  catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                'Failed to send notification to Program Chair',
                [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString(),
                    'program_chair_id' => $programChair->id ?? null,
                ]
            );

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get program chair for a specific program
     */
    public function getProgramChair(Request $request, int $programId)
    {
        $user = $request->user();

        if (! $user || ! $user->isDean()) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $college = $this->deanDashboard->resolveCollege($user);
        if (! $college) {
            return response()->json(['success' => false, 'message' => 'Unable to determine your college.'], 403);
        }

        $program = Program::where('id', $programId)
            ->where('college_id', $college->id)
            ->with('chairUser')
            ->first();

        if (! $program) {
            return response()->json([
                'success' => false,
                'message' => 'Program not found or not in your college.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $program->chair_id,
                'name' => $program->chairUser?->name ?? 'Unassigned',
                'email' => $program->chairUser?->email ?? '',
                'program_name' => $program->name,
                'program_id' => $program->id,
            ],
        ]);
    }
}
