<?php

namespace App\Services;

class ExcelExportService
{
    /**
     * Generate an Excel file from report data.
     * Uses HTML table format which Excel opens natively.
     *
     * @param array $data Report data
     * @param string $filename Output filename
     * @return \Illuminate\Http\Response
     */
    public function generate(array $data, string $filename)
    {
        $html = $this->buildHtml($data);

        return response($html, 200, [
            'Content-Type' => 'application/vnd.ms-excel',
            'Content-Disposition' => 'attachment; filename="' . $filename . '.xls"',
            'Content-Length' => strlen($html),
            'Cache-Control' => 'private, max-age=0, must-revalidate',
            'Pragma' => 'public',
        ]);
    }

    /**
     * Build the HTML content for the Excel file.
     *
     * @param array $data Report data
     * @return string
     */
    private function buildHtml(array $data): string
    {
        $reportType = $data['reportType'] ?? 'Report';
        $generatedAt = $data['generatedAt'] ?? now()->toDateTimeString();

        $html = '<!DOCTYPE html>' . "\n";
        $html .= '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' . "\n";
        $html .= '<head>' . "\n";
        $html .= '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . "\n";
        $html .= '<!--[if gte mso 9]><xml><x:ExcelWorkbook><x:ExcelWorksheets><x:ExcelWorksheet><x:Name>' . htmlspecialchars($reportType) . '</x:Name><x:WorksheetOptions><x:DisplayGridlines/></x:WorksheetOptions></x:ExcelWorksheet></x:ExcelWorksheets></x:ExcelWorkbook></xml><![endif]-->' . "\n";
        $html .= '<style>' . "\n";
        $html .= 'body { font-family: Calibri, Arial, sans-serif; font-size: 11pt; }' . "\n";
        $html .= 'table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }' . "\n";
        $html .= 'th { background-color: #4472C4; color: white; font-weight: bold; text-align: left; padding: 6px 10px; border: 1px solid #2F5597; }' . "\n";
        $html .= 'td { padding: 5px 10px; border: 1px solid #D9E2F3; }' . "\n";
        $html .= 'tr:nth-child(even) { background-color: #E9EFF7; }' . "\n";
        $html .= '.title { font-size: 18pt; font-weight: bold; color: #1F3864; margin-bottom: 5px; }' . "\n";
        $html .= '.subtitle { font-size: 10pt; color: #666; margin-bottom: 20px; }' . "\n";
        $html .= '.section-header { font-size: 13pt; font-weight: bold; color: #1F3864; background-color: #D6E0F0; padding: 8px 10px; margin-top: 20px; margin-bottom: 10px; }' . "\n";
        $html .= '.summary-table { width: 50%; }' . "\n";
        $html .= '.summary-table td:first-child { font-weight: bold; background-color: #F2F2F2; width: 60%; }' . "\n";
        $html .= '.info-table { width: 50%; }' . "\n";
        $html .= '.info-table td:first-child { font-weight: bold; background-color: #F2F2F2; width: 40%; }' . "\n";
        $html .= '.percent { color: #C00000; font-weight: bold; }' . "\n";
        $html .= '.compliant { color: #008000; }' . "\n";
        $html .= '.non-compliant { color: #C00000; }' . "\n";
        $html .= '</style>' . "\n";
        $html .= '</head>' . "\n";
        $html .= '<body>' . "\n";

        // Title
        $html .= '<div class="title">' . htmlspecialchars($reportType) . '</div>' . "\n";
        $html .= '<div class="subtitle">Generated: ' . htmlspecialchars($generatedAt) . '</div>' . "\n";

        // Write entity info
        if (isset($data['program'])) {
            $html .= $this->buildInfoTable('Program Information', $data['program']);
        }
        if (isset($data['college'])) {
            $html .= $this->buildInfoTable('College Information', $data['college']);
        }
        if (isset($data['area'])) {
            $html .= $this->buildInfoTable('Area Information', $data['area']);
        }
        if (isset($data['cycle'])) {
            $html .= $this->buildInfoTable('Accreditation Cycle Information', $data['cycle']);
        }

        // Write summary
        if (isset($data['summary'])) {
            $html .= $this->buildSummaryTable($data['summary']);
        }

        // Write areas table
        if (isset($data['areas']) && count($data['areas']) > 0) {
            $html .= $this->buildAreasTable($data['areas']);
        }

        // Write programs table
        if (isset($data['programs']) && count($data['programs']) > 0) {
            $html .= $this->buildProgramsTable($data['programs']);
        }

        // Write cycles
        if (isset($data['cycles']) && count($data['cycles']) > 0) {
            $html .= $this->buildCyclesTable($data['cycles']);
        }

        // Write documents table
        if (isset($data['documents']) && count($data['documents']) > 0) {
            $html .= $this->buildDocumentsTable($data['documents']);
        }

        // Write tasks table
        if (isset($data['tasks']) && count($data['tasks']) > 0) {
            $html .= $this->buildTasksTable($data['tasks']);
        }

        // Write reviews table
        if (isset($data['reviews']) && count($data['reviews']) > 0) {
            $html .= $this->buildReviewsTable($data['reviews']);
        }

        // Write members table
        if (isset($data['members']) && count($data['members']) > 0) {
            $html .= $this->buildMembersTable($data['members']);
        }

        $html .= '</body>' . "\n";
        $html .= '</html>' . "\n";

        return $html;
    }

    /**
     * Build an info table (key-value pairs).
     */
    private function buildInfoTable(string $title, array $data): string
    {
        $html = '<div class="section-header">' . htmlspecialchars($title) . '</div>' . "\n";
        $html .= '<table class="info-table">' . "\n";
        foreach ($data as $key => $value) {
            $label = $this->formatLabel($key);
            $displayValue = $this->formatValue($value);
            $html .= '<tr><td>' . htmlspecialchars($label) . '</td><td>' . htmlspecialchars($displayValue) . '</td></tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build a summary table.
     */
    private function buildSummaryTable(array $summary): string
    {
        $html = '<div class="section-header">Summary</div>' . "\n";
        $html .= '<table class="summary-table">' . "\n";
        foreach ($summary as $key => $value) {
            $label = $this->formatLabel($key);
            $displayValue = $this->formatValue($value);
            $cssClass = '';
            if (str_contains(strtolower($key), 'percent')) {
                $cssClass = ' class="percent"';
            }
            $html .= '<tr><td>' . htmlspecialchars($label) . '</td><td' . $cssClass . '>' . htmlspecialchars($displayValue) . '</td></tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the areas table.
     */
    private function buildAreasTable(array $areas): string
    {
        $html = '<div class="section-header">Areas</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Area Name</th><th>Status</th><th>Program</th><th>Chair</th><th>Documents</th><th>Compliance</th></tr>' . "\n";
        foreach ($areas as $area) {
            $compliance = $area['complianceLevel'] ?? ($area['hasEvidence'] ? 'Compliant' : 'Non-Compliant');
            $cssClass = ($compliance === 'Compliant') ? 'compliant' : 'non-compliant';
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($area['areaName'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($area['areaStatus'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($area['programName'] ?? $area['programCode'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($area['chairName'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($area['documentCount'] ?? 0)) . '</td>';
            $html .= '<td class="' . $cssClass . '">' . htmlspecialchars($compliance) . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the programs table.
     */
    private function buildProgramsTable(array $programs): string
    {
        $html = '<div class="section-header">Programs</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Program</th><th>Code</th><th>Chair</th><th>Areas</th><th>Documents</th><th>Tasks</th><th>Overdue</th><th>Reviews</th><th>Pending</th><th>Compliance %</th></tr>' . "\n";
        foreach ($programs as $program) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($program['programName'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($program['programCode'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($program['chair'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['totalAreas'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['totalDocuments'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['totalTasks'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['overdueTasks'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['totalReviews'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($program['pendingReviews'] ?? 0)) . '</td>';
            $html .= '<td class="percent">' . htmlspecialchars((string) ($program['compliancePercent'] ?? 0)) . '%</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the cycles table.
     */
    private function buildCyclesTable(array $cycles): string
    {
        $html = '<div class="section-header">Accreditation Cycles</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Level</th><th>Status</th><th>Readiness</th><th>Valid Until</th><th>Scheduled Visit</th><th>Areas</th><th>Documents</th><th>Tasks</th><th>Overdue</th></tr>' . "\n";
        foreach ($cycles as $cycle) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($cycle['level'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($cycle['status'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($cycle['readiness'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($cycle['validUntil'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($cycle['scheduledVisit'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($cycle['totalAreas'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($cycle['totalDocuments'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($cycle['totalTasks'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($cycle['overdueTasks'] ?? 0)) . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the documents table.
     */
    private function buildDocumentsTable(array $documents): string
    {
        $html = '<div class="section-header">Documents</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Title</th><th>Description</th><th>School Year</th><th>Status</th><th>Version</th><th>Uploaded By</th><th>Versions</th><th>Created</th></tr>' . "\n";
        foreach ($documents as $doc) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($doc['title'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($doc['description'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($doc['schoolYear'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($doc['status'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($doc['currentVersion'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars($doc['uploadedBy'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($doc['versionCount'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars($doc['createdAt'] ?? '') . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the tasks table.
     */
    private function buildTasksTable(array $tasks): string
    {
        $html = '<div class="section-header">Tasks</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Title</th><th>Priority</th><th>Status</th><th>Due Date</th><th>Created By</th><th>Assignees</th></tr>' . "\n";
        foreach ($tasks as $task) {
            $assignees = '';
            if (isset($task['assignees']) && is_array($task['assignees'])) {
                $assignees = implode(', ', array_map(fn($a) => $a['name'] ?? '', $task['assignees']));
            }
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($task['title'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($task['priority'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($task['status'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($task['dueDate'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($task['createdBy'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($assignees) . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the reviews table.
     */
    private function buildReviewsTable(array $reviews): string
    {
        $html = '<div class="section-header">Reviews</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Status</th><th>Submitted By</th><th>Submitted At</th><th>Completed At</th><th>Comments</th><th>Terminal</th></tr>' . "\n";
        foreach ($reviews as $review) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($review['currentStatus'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($review['submittedBy'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($review['submittedAt'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($review['completedAt'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars((string) ($review['commentCount'] ?? 0)) . '</td>';
            $html .= '<td>' . htmlspecialchars($review['isTerminal'] ? 'Yes' : 'No') . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Build the members table.
     */
    private function buildMembersTable(array $members): string
    {
        $html = '<div class="section-header">Members</div>' . "\n";
        $html .= '<table>' . "\n";
        $html .= '<tr><th>Name</th><th>Email</th><th>Role</th></tr>' . "\n";
        foreach ($members as $member) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($member['name'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($member['email'] ?? '') . '</td>';
            $html .= '<td>' . htmlspecialchars($member['role'] ?? '') . '</td>';
            $html .= '</tr>' . "\n";
        }
        $html .= '</table>' . "\n";
        return $html;
    }

    /**
     * Format a label from camelCase to Title Case.
     */
    private function formatLabel(string $key): string
    {
        $result = preg_replace('/([a-z])([A-Z])/', '$1 $2', $key);
        return ucfirst($result);
    }

    /**
     * Format a value for display.
     */
    private function formatValue(mixed $value): string
    {
        if (is_null($value)) {
            return 'N/A';
        }
        if (is_bool($value)) {
            return $value ? 'Yes' : 'No';
        }
        if (is_array($value)) {
            return json_encode($value);
        }
        return (string) $value;
    }
}