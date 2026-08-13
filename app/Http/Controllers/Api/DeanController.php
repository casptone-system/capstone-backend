<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DeanController extends Controller
{
    public function dashboard(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Dean')) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $collegeId = $request->query('college_id');
        $college = null;

        if ($collegeId) {
            $college = College::find($collegeId);
            if (! $college) {
                return response()->json(['success' => false, 'message' => 'College not found.'], 404);
            }
        } else {
            $college = $user->program?->college ?? $user->team?->program?->college;
        }

        if (! $college) {
            return response()->json(['success' => true, 'data' => [
                'stats' => [],
                'programs' => [],
                'pendingDocuments' => [],
            ]]);
        }

        $programs = Program::where('college_id', $college->id)
            ->with(['college', 'chairUser'])
            ->get();

        $programIds = $programs->pluck('id');

        $documents = Document::whereIn('program_id', $programIds)
            ->with(['program', 'uploader'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $activeProgramChairCount = Program::where('college_id', $college->id)
            ->whereNotNull('chair_id')
            ->distinct('chair_id')
            ->count('chair_id');

        $facultyCount = User::whereIn('program_id', $programIds)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Faculty'))
            ->count();

        $activeFacultyCount = User::whereIn('program_id', $programIds)
            ->whereHas('roles', fn ($query) => $query->where('name', 'Faculty'))
            ->whereNotNull('email_verified_at')
            ->count();

        $avgCompliance = $programs->avg('compliance_score') ?? 0;
        $pendingDocuments = $documents->filter(fn ($document) => $document->status !== 'Archived')->count();
        $atRiskPrograms = $programs->filter(fn ($program) => (int) $program->compliance_score < 70)->count();

        return response()->json(['success' => true, 'data' => [
            'dean' => [
                'id' => $user->id,
                'name' => trim(sprintf('%s %s %s', $user->first_name, $user->middle_name ?? '', $user->last_name)),
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'position' => 'Dean',
                'role' => 'Dean',
                'department' => $college->name,
            ],
            'college' => [
                'id' => $college->id,
                'name' => $college->name,
            ],
            'stats' => [
                ['label' => 'Programs', 'value' => (string) $programs->count(), 'type' => 'programs'],
                ['label' => 'Overall Compliance', 'value' => round($avgCompliance, 1) . '%', 'type' => 'compliance'],
                ['label' => 'Pending Reviews', 'value' => (string) $pendingDocuments, 'type' => 'pending'],
                ['label' => 'At-Risk Programs', 'value' => (string) $atRiskPrograms, 'type' => 'risk'],
                ['label' => 'Faculty Participation', 'value' => $facultyCount ? round(($activeFacultyCount / $facultyCount) * 100, 1) . '%' : '0%', 'type' => 'faculty'],
                ['label' => 'Active Program Chairs', 'value' => (string) $activeProgramChairCount, 'type' => 'chairs'],
            ],
            'programs' => $programs->map(function ($program) {
                return [
                    'id' => $program->id,
                    'name' => $program->name,
                    'code' => $program->code,
                    'chair' => $program->chairUser?->name ?? $program->chair,
                    'accreditationStatus' => $program->accreditation_status,
                    'complianceScore' => (int) $program->compliance_score,
                    'documentCount' => Document::where('program_id', $program->id)->count(),
                ];
            })->values(),
            'pendingDocuments' => $documents->map(function ($document) {
                return [
                    'id' => $document->id,
                    'title' => $document->title,
                    'program' => $document->program?->name,
                    'submittedBy' => $document->uploader?->name,
                    'status' => $document->status,
                    'submittedAt' => $document->created_at?->toIso8601String(),
                ];
            })->values(),
        ]]);
    }

    public function programs(Request $request)
    {
        $user = $request->user();

        if (! $user || ! $user->hasRole('Dean')) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        $college = $user->program?->college ?? $user->team?->program?->college;
        if (! $college) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $query = Program::where('college_id', $college->id)->with(['college']);
        if ($request->filled('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('code', 'like', "%{$request->search}%");
            });
        }

        $programs = $query->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Dean programs retrieved successfully.',
            'data' => $programs,
        ]);
    }
}
