<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowStatus;
use App\Models\Program;
use App\Models\User;
use App\Services\AreaProgressService;
use App\Support\AreaDocumentRules;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EvidenceLinkTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{program: Program, area: AccreditationArea, parameter: AccreditationParameter, row: ParameterContentRow, chair: User}
     */
    private function makeRowWithChair(): array
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $chair = User::factory()->create();
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'chair_id' => $chair->id,
            'code' => 'area-1',
            'name' => 'Area 1',
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

        return compact('program', 'area', 'parameter', 'row', 'chair');
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    public function test_link_counts_as_uploaded_evidence_and_completes_only_when_approved(): void
    {
        ['program' => $program, 'area' => $area, 'row' => $row, 'chair' => $chair] = $this->makeRowWithChair();

        $response = $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => 'https://www.youtube.com/watch?v=abc123',
            'title' => 'Campus tour',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.kind', Document::KIND_LINK);
        $response->assertJsonPath('data.url', 'https://www.youtube.com/watch?v=abc123');
        $response->assertJsonPath('data.title', 'Campus tour');
        $response->assertJsonPath('data.status', 'Active');

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'link_added',
            'user_id' => $chair->id,
        ]);

        ParameterRowStatus::create([
            'content_row_id' => $row->id,
            'is_done' => true,
            'done_by' => $chair->id,
            'done_at' => now(),
        ]);

        $progress = app(AreaProgressService::class);
        $progress->refresh($area->fresh());
        $counts = $progress->countsForArea($area->fresh());

        $this->assertSame(1, $counts['total']);
        $this->assertSame(0, $counts['completed']);
        $this->assertSame(1, $counts['pending']);
        $this->assertSame(0, $area->fresh()->progress_percent);

        $programChair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $program->update(['chair_id' => $programChair->id]);
        $documentId = $response->json('data.id');

        $this->actingAs($programChair)
            ->postJson("/api/documents/{$documentId}/approve")
            ->assertOk();

        $counts = $progress->countsForArea($area->fresh());
        $this->assertSame(1, $counts['completed']);
        $this->assertSame(0, $counts['pending']);
        $this->assertSame(100, $area->fresh()->progress_percent);
    }

    public function test_remove_all_clears_links_and_unmarks_done(): void
    {
        ['row' => $row, 'chair' => $chair] = $this->makeRowWithChair();

        $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => 'https://drive.google.com/file/d/abc123/view',
            'title' => 'Drive folder',
        ])->assertCreated();

        ParameterRowStatus::create([
            'content_row_id' => $row->id,
            'is_done' => true,
            'done_by' => $chair->id,
            'done_at' => now(),
        ]);

        $this->actingAs($chair)
            ->deleteJson("/api/parameter-rows/{$row->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.hasFile', false)
            ->assertJsonPath('data.isDone', false);

        $this->assertSame(0, Document::query()->where('content_row_id', $row->id)->count());
        $this->assertFalse((bool) ParameterRowStatus::query()->where('content_row_id', $row->id)->value('is_done'));
        $this->assertTrue(AuditLog::query()->where('event', 'link_removed')->where('user_id', $chair->id)->exists());
    }

    public function test_link_validation_rejects_bad_schemes_and_duplicates(): void
    {
        ['row' => $row, 'chair' => $chair] = $this->makeRowWithChair();

        foreach (['javascript:alert(1)', 'data:text/html,hi', 'file:///C:/secret.pdf', 'ftp://example.com/video'] as $url) {
            $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
                'url' => $url,
            ])->assertStatus(422);
        }

        $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => '  https://vimeo.com/123  ',
        ])->assertCreated()
            ->assertJsonPath('data.url', 'https://vimeo.com/123')
            ->assertJsonPath('data.title', 'vimeo.com');

        $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => 'https://vimeo.com/123',
        ])->assertStatus(422)
            ->assertJsonPath('errors.url.0', 'This link is already attached to this row.');
    }

    public function test_area_members_can_add_links_and_only_the_chair_can_remove_them(): void
    {
        ['area' => $area, 'row' => $row, 'parameter' => $parameter] = $this->makeRowWithChair();
        $member = $this->userWithRole(RoleSlug::FACULTY);
        AreaMember::create(['area_id' => $area->id, 'user_id' => $member->id, 'role' => 'member']);
        $outsider = $this->userWithRole(RoleSlug::FACULTY);

        $this->actingAs($outsider)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => 'https://example.com/video',
        ])->assertForbidden();

        $created = $this->actingAs($member)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => 'https://example.com/video',
            'title' => 'Walkthrough',
        ])->assertCreated();

        $this->actingAs($member)
            ->deleteJson('/api/documents/'.$created->json('data.id'))
            ->assertForbidden();

        $programChair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $area->cycle->program->update(['chair_id' => $programChair->id]);

        $this->actingAs($programChair)
            ->getJson("/api/parameters/{$parameter->id}/rows")
            ->assertOk()
            ->assertJsonPath('data.0.documents.0.kind', Document::KIND_LINK)
            ->assertJsonPath('data.0.documents.0.url', 'https://example.com/video')
            ->assertJsonPath('data.0.hasFile', true);
    }

    public function test_area_members_can_remove_file_evidence_but_outsiders_cannot(): void
    {
        ['program' => $program, 'area' => $area, 'row' => $row] = $this->makeRowWithChair();
        $member = $this->userWithRole(RoleSlug::FACULTY);
        AreaMember::create(['area_id' => $area->id, 'user_id' => $member->id, 'role' => 'member']);
        $outsider = $this->userWithRole(RoleSlug::FACULTY);

        $makeFile = fn () => Document::factory()->create([
            'program_id' => $program->id,
            'area_id' => $area->id,
            'content_row_id' => $row->id,
            'kind' => Document::KIND_FILE,
            'uploaded_by' => $member->id,
        ]);

        // Single-file remove (AreaFileThumbnail "x" -> DELETE /documents/{id}).
        $single = $makeFile();
        $this->actingAs($outsider)->deleteJson('/api/documents/'.$single->id)->assertForbidden();
        $this->actingAs($member)->deleteJson('/api/documents/'.$single->id)->assertOk();
        $this->assertDatabaseMissing('documents', ['id' => $single->id]);

        // Remove-all (row trash -> DELETE /parameter-rows/{row}/documents).
        $bulk = $makeFile();
        $this->actingAs($outsider)->deleteJson("/api/parameter-rows/{$row->id}/documents")->assertForbidden();
        $this->actingAs($member)
            ->deleteJson("/api/parameter-rows/{$row->id}/documents")
            ->assertOk()
            ->assertJsonPath('data.hasFile', false);
        $this->assertDatabaseMissing('documents', ['id' => $bulk->id]);
    }

    public function test_compiled_pdf_lists_the_link_instead_of_omitting_it(): void
    {
        ['row' => $row, 'chair' => $chair] = $this->makeRowWithChair();
        $url = 'https://drive.google.com/file/d/abc123/view';

        $this->actingAs($chair)->postJson("/api/parameter-rows/{$row->id}/links", [
            'url' => $url,
            'title' => 'Drive walkthrough',
        ])->assertCreated();

        $response = $this->actingAs($chair)->get("/api/parameter-rows/{$row->id}/compiled-pdf");

        $response->assertOk();
        $this->assertStringContainsString('Drive walkthrough', $response->getContent());
        $this->assertStringContainsString($url, $response->getContent());
        $this->assertStringContainsString('Evidence link', $response->getContent());
    }

    public function test_links_do_not_consume_the_pdf_upload_cap(): void
    {
        ['program' => $program, 'row' => $row, 'chair' => $chair] = $this->makeRowWithChair();

        Document::factory()->create([
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'kind' => Document::KIND_LINK,
            'url' => 'https://example.com/one',
            'uploaded_by' => $chair->id,
        ]);

        AreaDocumentRules::assertRowHasCapacity($row->id);

        Document::factory()->count(AreaDocumentRules::maxFilesPerRow())->create([
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'kind' => Document::KIND_FILE,
            'uploaded_by' => $chair->id,
        ]);

        $this->expectException(ValidationException::class);
        AreaDocumentRules::assertRowHasCapacity($row->id);
    }
}
