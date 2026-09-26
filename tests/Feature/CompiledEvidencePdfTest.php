<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use App\Services\EvidenceStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CompiledEvidencePdfTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }

    /**
     * @return array{college: College, program: Program, area: AccreditationArea, parameter: AccreditationParameter, row: ParameterContentRow}
     */
    private function makeAreaWithRow(): array
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1 – Vision, Mission, Goals and Objectives',
        ]);
        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'A',
            'name' => 'Statement of Vision',
            'sort_order' => 1,
        ]);
        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'VMGO is displayed in classrooms.',
            'sort_order' => 1,
        ]);

        return compact('college', 'program', 'area', 'parameter', 'row');
    }

    private function attachPdf(ParameterContentRow $row, User $uploader, Program $program): Document
    {
        $document = Document::factory()->create([
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'title' => 'Classroom display photo set',
            'status' => 'Active',
            'uploaded_by' => $uploader->id,
            'current_version' => 1,
        ]);

        $path = "documents/{$document->id}/v1.pdf";
        $pdf = $this->demoPdf();
        app(EvidenceStorage::class)->put($path, $pdf);

        DocumentVersion::factory()->create([
            'document_id' => $document->id,
            'version' => 1,
            'file_path' => $path,
            'original_name' => 'classroom-display.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => strlen($pdf),
            'uploaded_by' => $uploader->id,
        ]);

        return $document;
    }

    private function demoPdf(): string
    {
        return "%PDF-1.4\n".
            "1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n".
            "2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n".
            "3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 612 792]/Contents 4 0 R/Resources<</Font<</F1 5 0 R>>>>>>endobj\n".
            "4 0 obj<</Length 68>>stream\n".
            "BT /F1 16 Tf 72 720 Td (ADAMS demo accreditation evidence) Tj ET\n".
            "endstream\nendobj\n".
            "5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n".
            "trailer<</Root 1 0 R>>\n%%EOF\n";
    }

    public function test_area_chair_can_download_compiled_instrument_pdf(): void
    {
        ['area' => $area, 'parameter' => $parameter, 'row' => $row, 'program' => $program] = $this->makeAreaWithRow();
        $chair = User::factory()->create([
            'first_name' => 'Maria',
            'middle_name' => null,
            'last_name' => 'Santos',
            'name' => 'Maria Santos',
        ]);
        $area->update(['chair_id' => $chair->id]);
        $this->attachPdf($row, $chair, $program);

        $response = $this->actingAs($chair)->get("/api/parameters/{$parameter->id}/compiled-pdf");

        $response->assertOk();
        $this->assertStringStartsWith('application/pdf', (string) $response->headers->get('content-type'));
        $this->assertStringContainsString('compiled.pdf', (string) $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('Compiled Evidence Packet', $response->getContent());
        $this->assertStringContainsString('ADAMS demo accreditation evidence', $response->getContent());
        $this->assertStringContainsString('Maria Santos', $response->getContent());
        $this->assertStringContainsString('Area Chair', $response->getContent());
        $this->assertStringContainsString('Prepared by', $response->getContent());
        $this->assertFootnoteOnEveryPage($response->getContent());
    }

    public function test_area_member_can_download_compiled_row_pdf(): void
    {
        ['area' => $area, 'row' => $row, 'program' => $program] = $this->makeAreaWithRow();
        $member = $this->userWithRole('faculty', [
            'first_name' => 'Jose',
            'middle_name' => null,
            'last_name' => 'Reyes',
            'name' => 'Jose Reyes',
        ]);
        AreaMember::create(['area_id' => $area->id, 'user_id' => $member->id, 'role' => 'member']);
        $this->attachPdf($row, $member, $program);

        $response = $this->actingAs($member)->get("/api/parameter-rows/{$row->id}/compiled-pdf");

        $response->assertOk();
        $this->assertStringStartsWith('%PDF', $response->getContent());
        $this->assertStringContainsString('Evidence row', $response->getContent());
        $this->assertStringContainsString('Jose Reyes', $response->getContent());
        $this->assertStringContainsString('Area Member', $response->getContent());
        $this->assertStringContainsString('Prepared by', $response->getContent());
        $this->assertFootnoteOnEveryPage($response->getContent());
    }

    public function test_unassigned_users_cannot_download_compiled_pdf(): void
    {
        ['parameter' => $parameter, 'row' => $row, 'program' => $program, 'college' => $college] = $this->makeAreaWithRow();
        $outsider = $this->userWithRole('faculty');

        $this->actingAs($outsider)
            ->get("/api/parameters/{$parameter->id}/compiled-pdf")
            ->assertForbidden();

        $this->actingAs($outsider)
            ->get("/api/parameter-rows/{$row->id}/compiled-pdf")
            ->assertForbidden();

        $dean = $this->userWithRole('dean', ['college_id' => $college->id]);
        $this->actingAs($dean)
            ->get("/api/parameters/{$parameter->id}/compiled-pdf")
            ->assertForbidden();

        $programChair = $this->userWithRole('program-chair');
        $program->update(['chair_id' => $programChair->id]);
        $this->actingAs($programChair)
            ->get("/api/parameter-rows/{$row->id}/compiled-pdf")
            ->assertForbidden();
    }

    private function assertFootnoteOnEveryPage(string $pdf): void
    {
        $this->assertMatchesRegularExpression('/\/Type\s*\/Pages\s*\/Kids\s*\[.*?\]\s*\/Count\s+(\d+)/s', $pdf);
        preg_match('/\/Type\s*\/Pages\s*\/Kids\s*\[.*?\]\s*\/Count\s+(\d+)/s', $pdf, $matches);
        $pageCount = (int) ($matches[1] ?? 0);
        $this->assertGreaterThan(0, $pageCount);
        $this->assertSame($pageCount, substr_count($pdf, 'ADAMS compiled evidence - Page '));
        $this->assertGreaterThanOrEqual($pageCount, substr_count($pdf, 'Prepared by '));
    }

    public function test_empty_row_returns_unprocessable(): void
    {
        ['area' => $area, 'row' => $row] = $this->makeAreaWithRow();
        $chair = User::factory()->create();
        $area->update(['chair_id' => $chair->id]);

        $this->actingAs($chair)
            ->get("/api/parameter-rows/{$row->id}/compiled-pdf")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
