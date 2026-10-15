<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use App\Notifications\EvidenceReleasedToAccreditorNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccreditorSubmissionTest extends TestCase
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
     * @return array{college: College, program: Program, cycle: AccreditationCycle, area: AccreditationArea, parameter: AccreditationParameter, row: ParameterContentRow}
     */
    private function makeProgram(): array
    {
        $college = College::factory()->create(['name' => 'College of Education']);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'Bachelor of Elementary Education',
        ]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => 'Level I',
        ]);
        $program->update(['active_cycle_id' => $cycle->id]);

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Vision, Mission, Goals and Objectives',
        ]);
        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'A',
            'name' => 'Statement of Vision, Mission, Goals and Objectives',
            'sort_order' => 1,
        ]);
        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'The institution has a published VMGO.',
            'sort_order' => 1,
        ]);

        return compact('college', 'program', 'cycle', 'area', 'parameter', 'row');
    }

    public function test_program_chair_submission_reveals_evidence_to_the_accreditor(): void
    {
        Notification::fake();

        ['college' => $college, 'program' => $program, 'area' => $area, 'parameter' => $parameter, 'row' => $row] = $this->makeProgram();

        $chair = $this->userWithRole('program-chair');
        $program->update(['chair_id' => $chair->id]);
        $accreditor = $this->userWithRole('accreditor');
        $document = Document::factory()->create([
            'program_id' => $program->id,
            'area_id' => $area->id,
            'content_row_id' => $row->id,
            'title' => 'VMGO evidence',
        ]);

        $this->actingAs($accreditor)
            ->getJson('/api/accreditor/evidence/colleges')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($accreditor)
            ->getJson("/api/accreditation-areas/{$area->id}/parameters")
            ->assertForbidden();

        $this->actingAs($accreditor)
            ->getJson("/api/documents/{$document->id}/versions")
            ->assertForbidden();

        $this->actingAs($chair)
            ->getJson('/api/program-chair/accreditor-submission')
            ->assertOk()
            ->assertJsonPath('data.submitted', false)
            ->assertJsonPath('data.programName', 'Bachelor of Elementary Education');

        $this->actingAs($chair)
            ->postJson('/api/program-chair/accreditor-submission')
            ->assertCreated()
            ->assertJsonPath('data.submitted', true)
            ->assertJsonPath('data.level', 'Level I');

        Notification::assertSentTo($accreditor, EvidenceReleasedToAccreditorNotification::class);

        $this->actingAs($chair)
            ->postJson('/api/program-chair/accreditor-submission')
            ->assertOk()
            ->assertJsonPath('message', 'This accreditation was already submitted to the accreditor.');

        Notification::assertSentToTimes($accreditor, EvidenceReleasedToAccreditorNotification::class, 1);

        $this->actingAs($accreditor)
            ->getJson('/api/accreditor/evidence/colleges')
            ->assertOk()
            ->assertJsonPath('data.0.id', $college->id)
            ->assertJsonPath('data.0.name', 'College of Education');

        $this->actingAs($accreditor)
            ->getJson("/api/accreditor/evidence/colleges/{$college->id}/programs")
            ->assertOk()
            ->assertJsonPath('data.0.id', $program->id);

        $this->actingAs($accreditor)
            ->getJson("/api/accreditor/evidence/programs/{$program->id}/areas")
            ->assertOk()
            ->assertJsonPath('data.0.id', $area->id);

        $this->actingAs($accreditor)
            ->getJson("/api/accreditation-areas/{$area->id}/parameters")
            ->assertOk()
            ->assertJsonPath('data.0.id', $parameter->id);

        $this->actingAs($accreditor)
            ->getJson("/api/parameters/{$parameter->id}/rows")
            ->assertOk()
            ->assertJsonPath('data.0.id', $row->id)
            ->assertJsonPath('data.0.documents.0.id', $document->id)
            ->assertJsonPath('data.0.canComment', false)
            ->assertJsonPath('data.0.commentCount', 0);

        $this->actingAs($accreditor)
            ->getJson("/api/documents/{$document->id}/versions")
            ->assertOk();

        $this->actingAs($accreditor)
            ->getJson("/api/parameter-rows/{$row->id}/comments")
            ->assertForbidden();

        $this->actingAs($accreditor)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Noted during the visit.'])
            ->assertForbidden();
    }

    public function test_other_roles_cannot_submit_or_browse_the_accreditor_catalog(): void
    {
        ['program' => $program] = $this->makeProgram();
        $chair = $this->userWithRole('program-chair');
        $program->update(['chair_id' => $chair->id]);
        $outsider = $this->userWithRole('program-chair');
        $qa = $this->userWithRole('qa');

        $this->actingAs($outsider)
            ->postJson('/api/program-chair/accreditor-submission')
            ->assertStatus(422);

        $this->actingAs($qa)
            ->getJson('/api/accreditor/evidence/colleges')
            ->assertForbidden();

        $this->actingAs($qa)
            ->postJson('/api/program-chair/accreditor-submission')
            ->assertForbidden();
    }

    public function test_qa_can_still_open_unreleased_parameter_rows(): void
    {
        ['parameter' => $parameter] = $this->makeProgram();
        $qa = $this->userWithRole('qa');

        $this->actingAs($qa)
            ->getJson("/api/parameters/{$parameter->id}/rows")
            ->assertOk();
    }
}
