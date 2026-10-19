<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use App\Services\AreaProgressService;
use App\Support\ActiveCycle;
use App\Support\RoleSlug;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PublicExplorerController extends Controller
{
    private const CACHE_TTL_SECONDS = 60;

    private const AREA_META = [
        'area-1' => [
            'id' => 'area-1',
            'shortName' => 'Vision, Mission & Goals',
            'description' => 'Institutional direction, goals, and shared understanding.',
            'detail' => 'Review how institutional and program goals are defined, communicated, and aligned with academic work.',
        ],
        'area-2' => [
            'id' => 'area-2',
            'shortName' => 'Faculty',
            'description' => 'Faculty qualifications, development, and engagement.',
            'detail' => 'Bring faculty credentials, development, performance records, and engagement evidence into view.',
        ],
        'area-3' => [
            'id' => 'area-3',
            'shortName' => 'Curriculum & Instruction',
            'description' => 'Curriculum design, delivery, and learning outcomes.',
            'detail' => 'Connect curriculum documents, teaching evidence, assessment practices, and learning outcomes.',
        ],
        'area-4' => [
            'id' => 'area-4',
            'shortName' => 'Support to Students',
            'description' => 'Services that help students thrive and progress.',
            'detail' => 'Organize student services, advising, guidance, wellness, and support program evidence.',
        ],
        'area-5' => [
            'id' => 'area-5',
            'shortName' => 'Research',
            'description' => 'Research activity, outputs, and institutional impact.',
            'detail' => 'Track research policies, projects, publications, utilization, and institutional support.',
        ],
        'area-6' => [
            'id' => 'area-6',
            'shortName' => 'Extension',
            'description' => 'Community engagement and extension initiatives.',
            'detail' => 'Show how extension initiatives respond to community needs and document participation and outcomes.',
        ],
        'area-7' => [
            'id' => 'area-7',
            'shortName' => 'Library',
            'description' => 'Learning resources, facilities, and library services.',
            'detail' => 'Bring together evidence of learning resources, access, staffing, facilities, and library services.',
        ],
        'area-8' => [
            'id' => 'area-8',
            'shortName' => 'Physical Facilities',
            'description' => 'Spaces and resources that support learning.',
            'detail' => 'Review the availability, condition, accessibility, and maintenance of physical learning environments.',
        ],
        'area-9' => [
            'id' => 'area-9',
            'shortName' => 'Laboratories',
            'description' => 'Laboratory resources, safety, and utilization.',
            'detail' => 'Organize laboratory equipment, safety practices, maintenance, and utilization evidence.',
        ],
        'area-10' => [
            'id' => 'area-10',
            'shortName' => 'Administration',
            'description' => 'Leadership, planning, and quality assurance.',
            'detail' => 'Connect governance, planning, quality assurance, and administrative support records.',
        ],
    ];

    public function __construct(private AreaProgressService $progress) {}

    public function analytics(): JsonResponse
    {
        $payload = Cache::remember('public.explorer.analytics', self::CACHE_TTL_SECONDS, function () {
            $programs = $this->programSnapshots();
            $complianceRate = $programs->isEmpty()
                ? 0
                : (int) round($programs->avg('compliance'));
            $totalDocuments = (int) Document::query()->count();
            $pendingReviews = (int) Review::query()
                ->whereNotIn('current_status', ['Ready', 'Rejected'])
                ->count();
            $programsOnTrack = $programs->where('onTrack', true)->count();

            return [
                'totalPrograms' => $programs->count(),
                'totalDocuments' => $totalDocuments,
                'complianceRate' => $complianceRate,
                'pendingReviews' => $pendingReviews,
                'accreditationAreas' => count(AccreditationArea::AACCUP_AREAS),
                'programsOnTrack' => $programsOnTrack,
                'sexDistribution' => $this->sexDistribution(),
                'complianceHistory' => $this->complianceHistory(),
                'documentStatus' => $this->documentStatusTotals(),
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Public analytics retrieved successfully.',
            'data' => $payload,
        ]);
    }

    public function programs(): JsonResponse
    {
        $payload = Cache::remember('public.explorer.programs', self::CACHE_TTL_SECONDS, function () {
            return $this->programSnapshots()->values()->all();
        });

        return response()->json([
            'success' => true,
            'message' => 'Public programs retrieved successfully.',
            'data' => $payload,
        ]);
    }

    public function departments(): JsonResponse
    {
        $payload = Cache::remember('public.explorer.departments', self::CACHE_TTL_SECONDS, function () {
            return College::query()
                ->orderBy('name')
                ->get(['id', 'name', 'code', 'campus'])
                ->map(function (College $college) {
                    $code = strtoupper(trim((string) ($college->code ?: '')));
                    $short = $this->shortCollegeName($college->name);

                    return [
                        'id' => (string) $college->id,
                        'name' => $college->name,
                        'shortName' => $short,
                        'code' => $code !== '' ? mb_substr($code, 0, 4) : mb_substr(strtoupper($short), 0, 2),
                        'departmentCode' => $code !== '' ? $code : 'COL'.$college->id,
                    ];
                })
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'message' => 'Public departments retrieved successfully.',
            'data' => $payload,
        ]);
    }

    public function accreditationAreas(): JsonResponse
    {
        $payload = Cache::remember('public.explorer.areas', self::CACHE_TTL_SECONDS, function () {
            $averages = $this->areaAverageScores();

            return collect(AccreditationArea::AACCUP_AREAS)->map(function (array $area) use ($averages) {
                $code = $area['code'];
                $meta = self::AREA_META[$code] ?? [
                    'id' => $code,
                    'shortName' => $area['name'],
                    'description' => '',
                    'detail' => '',
                ];

                return [
                    'id' => $code,
                    'name' => $area['name'],
                    'shortName' => $meta['shortName'],
                    'description' => $meta['description'],
                    'detail' => $meta['detail'],
                    'score' => (int) ($averages[$code] ?? 0),
                ];
            })->values()->all();
        });

        return response()->json([
            'success' => true,
            'message' => 'Public accreditation areas retrieved successfully.',
            'data' => $payload,
        ]);
    }

    public function cycles(): JsonResponse
    {
        $payload = Cache::remember('public.explorer.cycles', self::CACHE_TTL_SECONDS, function () {
            return AccreditationCycle::query()
                ->select('id', 'level', 'status', 'instrument_name', 'program_id', 'created_at')
                ->orderByDesc('id')
                ->limit(40)
                ->get()
                ->map(function (AccreditationCycle $cycle) {
                    $label = trim((string) ($cycle->instrument_name ?: $cycle->level ?: 'Cycle '.$cycle->id));

                    return [
                        'id' => (string) $cycle->id,
                        'name' => $label,
                        'level' => $cycle->level,
                        'status' => $cycle->status,
                        'programId' => $cycle->program_id,
                    ];
                })
                ->unique('id')
                ->values()
                ->all();
        });

        return response()->json([
            'success' => true,
            'message' => 'Public accreditation cycles retrieved successfully.',
            'data' => $payload,
        ]);
    }

    /**
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    private function programSnapshots()
    {
        $programs = Program::query()
            ->with(['college', 'activeCycle', 'accreditationCycles'])
            ->orderBy('name')
            ->get();

        $docStats = Document::query()
            ->selectRaw("program_id, status, COUNT(*) as total")
            ->groupBy('program_id', 'status')
            ->get()
            ->groupBy('program_id');

        $pendingByProgram = Review::query()
            ->selectRaw('accreditation_cycles.program_id as program_id, COUNT(*) as total')
            ->join('accreditation_cycles', 'accreditation_cycles.id', '=', 'reviews.cycle_id')
            ->whereNotIn('reviews.current_status', ['Ready', 'Rejected'])
            ->groupBy('accreditation_cycles.program_id')
            ->pluck('total', 'program_id');

        $monthKeys = $this->lastMonthKeys(9);

        return $programs->map(function (Program $program) use ($docStats, $pendingByProgram, $monthKeys) {
            $cycle = ActiveCycle::forProgram($program);
            $areas = $this->progress->activeAreasForProgram($program);
            $compliance = $areas->isEmpty()
                ? (int) ($program->compliance_score ?? 0)
                : (int) round($areas->avg(fn (AccreditationArea $area) => (int) ($area->progress_percent ?? 0)));

            $statusRows = $docStats->get($program->id, collect());
            $byStatus = $statusRows->pluck('total', 'status');
            $approved = (int) ($byStatus['Approved'] ?? 0);
            $draft = (int) ($byStatus['Draft'] ?? 0);
            $revision = (int) ($byStatus['Revision Requested'] ?? 0);
            $active = (int) ($byStatus['Active'] ?? 0);
            $archived = (int) ($byStatus['Archived'] ?? 0);
            $documents = $approved + $draft + $revision + $active + $archived;
            $pendingDocuments = $revision + $active;
            $rejectedDocuments = $revision;
            $pendingReviews = (int) ($pendingByProgram[$program->id] ?? 0);

            $areaPerformance = collect(AccreditationArea::AACCUP_AREAS)->map(function (array $def) use ($areas) {
                $match = $areas->firstWhere('code', $def['code']);

                return [
                    'id' => $def['code'],
                    'score' => (int) ($match?->progress_percent ?? 0),
                ];
            })->values()->all();

            $history = $this->programHistoryScores($program->id, $compliance, $monthKeys);
            $college = $program->college;
            $collegeCode = strtoupper(trim((string) ($college?->code ?: '')));

            return [
                'id' => (string) $program->id,
                'name' => $program->name,
                'code' => $program->code,
                'departmentId' => $college ? (string) $college->id : '',
                'departmentName' => $college?->name ?: 'Unassigned college',
                'departmentCode' => $collegeCode !== '' ? $collegeCode : 'N/A',
                'cycleId' => $cycle ? (string) $cycle->id : '',
                'cycleName' => $cycle
                    ? trim((string) ($cycle->instrument_name ?: $cycle->level ?: 'Active cycle'))
                    : 'No active cycle',
                'compliance' => $compliance,
                'documents' => $documents,
                'approvedDocuments' => $approved,
                'pendingDocuments' => $pendingDocuments,
                'rejectedDocuments' => $rejectedDocuments,
                'draftDocuments' => $draft,
                'pendingReviews' => $pendingReviews,
                'onTrack' => $compliance >= 70,
                'areaPerformance' => $areaPerformance,
                'history' => $history,
            ];
        });
    }

    /**
     * @return array{male: int, female: int, unset: int, total: int}
     */
    private function sexDistribution(): array
    {
        $rows = User::query()
            ->whereNull('users.deleted_at')
            ->whereHas('roles', function ($roleQuery) {
                $roleQuery->whereIn('name', [
                    RoleSlug::FACULTY,
                    RoleSlug::AREA_IN_CHARGE,
                    RoleSlug::PROGRAM_CHAIR,
                    RoleSlug::DEAN,
                    RoleSlug::QA,
                    RoleSlug::VPAA,
                ]);
            })
            ->selectRaw("LOWER(COALESCE(NULLIF(TRIM(sex), ''), 'unset')) as sex_key, COUNT(*) as total")
            ->groupBy('sex_key')
            ->pluck('total', 'sex_key');

        $male = (int) ($rows['male'] ?? 0);
        $female = (int) ($rows['female'] ?? 0);
        $unset = (int) collect($rows)->except(['male', 'female'])->sum();

        return [
            'male' => $male,
            'female' => $female,
            'unset' => $unset,
            'total' => $male + $female + $unset,
        ];
    }

    /**
     * @return list<array{month: string, score: int}>
     */
    private function complianceHistory(): array
    {
        $monthKeys = $this->lastMonthKeys(9);
        $labels = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $current = (int) round((float) Program::query()->avg('compliance_score') ?: 0);

        $approvedByMonth = Document::query()
            ->where('status', 'Approved')
            ->where('created_at', '>=', now()->subMonths(8)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $allByMonth = Document::query()
            ->where('created_at', '>=', now()->subMonths(8)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $cumulativeApproved = 0;
        $cumulativeAll = 0;
        $history = [];

        foreach ($monthKeys as $key) {
            $cumulativeApproved += (int) ($approvedByMonth[$key] ?? 0);
            $cumulativeAll += (int) ($allByMonth[$key] ?? 0);
            $ratio = $cumulativeAll > 0
                ? (int) round(($cumulativeApproved / $cumulativeAll) * 100)
                : $current;
            $parts = explode('-', $key);
            $monthIndex = max(1, min(12, (int) ($parts[1] ?? 1))) - 1;
            $history[] = [
                'month' => $labels[$monthIndex] ?? $key,
                'score' => max(0, min(100, $ratio > 0 ? $ratio : $current)),
            ];
        }

        if ($history !== [] && $current > 0) {
            $history[count($history) - 1]['score'] = $current;
        }

        return $history;
    }

    /**
     * @param  list<string>  $monthKeys
     * @return list<int>
     */
    private function programHistoryScores(int $programId, int $currentCompliance, array $monthKeys): array
    {
        $approvedByMonth = Document::query()
            ->where('program_id', $programId)
            ->where('status', 'Approved')
            ->where('created_at', '>=', now()->subMonths(8)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $allByMonth = Document::query()
            ->where('program_id', $programId)
            ->where('created_at', '>=', now()->subMonths(8)->startOfMonth())
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month_key, COUNT(*) as total")
            ->groupBy('month_key')
            ->pluck('total', 'month_key');

        $cumulativeApproved = 0;
        $cumulativeAll = 0;
        $scores = [];

        foreach ($monthKeys as $index => $key) {
            $cumulativeApproved += (int) ($approvedByMonth[$key] ?? 0);
            $cumulativeAll += (int) ($allByMonth[$key] ?? 0);
            if ($index === count($monthKeys) - 1) {
                $scores[] = $currentCompliance;
                continue;
            }
            $ratio = $cumulativeAll > 0
                ? (int) round(($cumulativeApproved / $cumulativeAll) * 100)
                : max(0, $currentCompliance - ((count($monthKeys) - 1 - $index) * 2));
            $scores[] = max(0, min(100, $ratio));
        }

        return $scores;
    }

    /**
     * @return array{approved: int, pending: int, rejected: int, draft: int}
     */
    private function documentStatusTotals(): array
    {
        $counts = Document::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'approved' => (int) ($counts['Approved'] ?? 0),
            'pending' => (int) (($counts['Active'] ?? 0) + ($counts['Revision Requested'] ?? 0)),
            'rejected' => (int) ($counts['Revision Requested'] ?? 0),
            'draft' => (int) ($counts['Draft'] ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function areaAverageScores(): array
    {
        $programs = Program::query()->with(['activeCycle', 'accreditationCycles'])->get();
        $buckets = [];

        foreach ($programs as $program) {
            foreach ($this->progress->activeAreasForProgram($program) as $area) {
                $code = (string) $area->code;
                if ($code === '') {
                    continue;
                }
                $buckets[$code] ??= [];
                $buckets[$code][] = (int) ($area->progress_percent ?? 0);
            }
        }

        $averages = [];
        foreach ($buckets as $code => $scores) {
            $averages[$code] = (int) round(array_sum($scores) / max(1, count($scores)));
        }

        return $averages;
    }

    /**
     * @return list<string>
     */
    private function lastMonthKeys(int $count): array
    {
        $keys = [];
        for ($i = $count - 1; $i >= 0; $i--) {
            $keys[] = now()->subMonths($i)->format('Y-m');
        }

        return $keys;
    }

    private function shortCollegeName(string $name): string
    {
        $trimmed = trim(preg_replace('/^(College|Institute|School)\s+of\s+/i', '', $name) ?: $name);

        return $trimmed !== '' ? $trimmed : $name;
    }
}
