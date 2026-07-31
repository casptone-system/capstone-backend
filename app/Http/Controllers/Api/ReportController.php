<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ExcelExportService;
use App\Services\PdfExportService;
use App\Services\ReportService;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private ReportService $reportService,
        private PdfExportService $pdfExportService,
        private ExcelExportService $excelExportService,
    ) {}

    /**
     * List available report types.
     */
    public function index()
    {
        return response()->json([
            'success' => true,
            'message' => 'Available report types retrieved successfully.',
            'data' => [
                [
                    'type' => 'compliance',
                    'name' => 'Compliance Report',
                    'description' => 'Overall compliance metrics across all programs and areas.',
                    'endpoint' => '/api/reports/compliance',
                    'formats' => ['json', 'pdf', 'excel'],
                    'filters' => ['program_id', 'college_id', 'cycle_id'],
                ],
                [
                    'type' => 'program',
                    'name' => 'Program Report',
                    'description' => 'Detailed report for a specific program including cycles, areas, documents, tasks, and reviews.',
                    'endpoint' => '/api/reports/programs/{program}',
                    'formats' => ['json', 'pdf', 'excel'],
                ],
                [
                    'type' => 'college',
                    'name' => 'College Report',
                    'description' => 'Detailed report for a specific college including all its programs and their accreditation status.',
                    'endpoint' => '/api/reports/colleges/{college}',
                    'formats' => ['json', 'pdf', 'excel'],
                ],
                [
                    'type' => 'area',
                    'name' => 'Area Report',
                    'description' => 'Detailed report for a specific accreditation area including documents, tasks, members, and reviews.',
                    'endpoint' => '/api/reports/areas/{area}',
                    'formats' => ['json', 'pdf', 'excel'],
                ],
                [
                    'type' => 'accreditation',
                    'name' => 'Accreditation Report',
                    'description' => 'Detailed report for a specific accreditation cycle including all areas and their status.',
                    'endpoint' => '/api/reports/accreditation-cycles/{cycle}',
                    'formats' => ['json', 'pdf', 'excel'],
                ],
            ],
        ], 200);
    }

    /**
     * Generate a compliance report.
     */
    public function compliance(Request $request)
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'exists:programs,id'],
            'college_id' => ['nullable', 'exists:colleges,id'],
            'cycle_id' => ['nullable', 'exists:accreditation_cycles,id'],
        ]);

        $data = $this->reportService->complianceReport($validated);
        $format = $request->query('format', 'json');
        $filename = 'compliance_report_' . now()->format('Y-m-d');

        return $this->formatResponse($data, $format, $filename);
    }

    /**
     * Generate a program report.
     */
    public function program(Request $request, int $program)
    {
        $data = $this->reportService->programReport($program);
        $format = $request->query('format', 'json');
        $filename = 'program_report_' . ($data['program']['code'] ?? $program) . '_' . now()->format('Y-m-d');

        return $this->formatResponse($data, $format, $filename);
    }

    /**
     * Generate a college report.
     */
    public function college(Request $request, int $college)
    {
        $data = $this->reportService->collegeReport($college);
        $format = $request->query('format', 'json');
        $filename = 'college_report_' . ($data['college']['code'] ?? $college) . '_' . now()->format('Y-m-d');

        return $this->formatResponse($data, $format, $filename);
    }

    /**
     * Generate an area report.
     */
    public function area(Request $request, int $area)
    {
        $data = $this->reportService->areaReport($area);
        $format = $request->query('format', 'json');
        $filename = 'area_report_' . $area . '_' . now()->format('Y-m-d');

        return $this->formatResponse($data, $format, $filename);
    }

    /**
     * Generate an accreditation cycle report.
     */
    public function accreditation(Request $request, int $cycle)
    {
        $data = $this->reportService->accreditationReport($cycle);
        $format = $request->query('format', 'json');
        $filename = 'accreditation_report_' . $cycle . '_' . now()->format('Y-m-d');

        return $this->formatResponse($data, $format, $filename);
    }

    /**
     * Format the response based on the requested format.
     *
     * @param array $data Report data
     * @param string $format Response format (json, pdf, excel)
     * @param string $filename Base filename for downloads
     * @return \Illuminate\Http\Response|\Illuminate\Http\JsonResponse
     */
    private function formatResponse(array $data, string $format, string $filename)
    {
        // Convert any nested Collections to plain arrays for export services
        if ($format === 'pdf' || $format === 'excel') {
            $data = json_decode(json_encode($data), true);
        }

        return match ($format) {
            'pdf' => $this->pdfExportService->generate($data, $filename),
            'excel' => $this->excelExportService->generate($data, $filename),
            default => response()->json([
                'success' => true,
                'message' => $data['reportType'] . ' generated successfully.',
                'data' => $data,
            ], 200),
        };
    }
}