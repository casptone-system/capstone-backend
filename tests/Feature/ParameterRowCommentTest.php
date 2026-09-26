<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowComment;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Server-side authorization and behaviour for the per-row comment thread
 * (Task 2/4 of the row-comments feature): area assignment OR OrgScope
 * reviewer visibility gates access; commenting never touches progress or
 * the Approve/Return workflow's own state transitions.
 */
class ParameterRowCommentTest extends TestCase
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
     * Builds a full College -> Program -> Cycle -> Area -> Parameter -> Row
     * chain, the minimum needed for OrgScope + area-assignment checks.
     *
     * @return array{college: College, program: Program, area: AccreditationArea, row: ParameterContentRow}
     */
    private function makeAreaWithRow(?College $college = null, ?Program $program = null): array
    {
        $college ??= College::factory()->create();
        $program ??= Program::factory()->create(['college_id' => $college->id]);

        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-'.fake()->unique()->numberBetween(1, 1000000),
        ]);

        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'p-'.fake()->unique()->numberBetween(1, 1000000),
            'name' => 'Parameter',
            'sort_order' => 1,
        ]);

        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Row content',
            'sort_order' => 1,
        ]);

        return compact('college', 'program', 'area', 'row');
    }

    public function test_area_chair_and_member_can_view_and_post_comments_on_their_own_row(): void
    {
        Notification::fake();

        ['area' => $area, 'row' => $row] = $this->makeAreaWithRow();

        $chair = User::factory()->create();
        $area->update(['chair_id' => $chair->id]);

        $member = User::factory()->create();
        AreaMember::create(['area_id' => $area->id, 'user_id' => $member->id, 'role' => 'member']);

        $this->actingAs($chair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Uploaded the syllabus.'])
            ->assertCreated()
            ->assertJsonPath('data.authorRole', 'Area Chair');

        $this->actingAs($member)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Feedback addressed.'])
            ->assertCreated()
            ->assertJsonPath('data.authorRole', 'Member');

        $this->actingAs($chair)
            ->getJson("/api/parameter-rows/{$row->id}/comments")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_users_outside_the_area_and_its_program_are_forbidden(): void
    {
        ['row' => $row] = $this->makeAreaWithRow();

        $otherAreaData = $this->makeAreaWithRow();
        $otherChair = User::factory()->create();
        $otherAreaData['area']->update(['chair_id' => $otherChair->id]);

        $this->actingAs($otherChair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Should not work'])
            ->assertForbidden();

        $unassignedFaculty = $this->userWithRole('faculty');
        $this->actingAs($unassignedFaculty)
            ->getJson("/api/parameter-rows/{$row->id}/comments")
            ->assertForbidden();
    }

    public function test_dean_can_comment_within_their_college_but_not_outside_it(): void
    {
        ['college' => $college, 'row' => $row] = $this->makeAreaWithRow();

        $dean = $this->userWithRole('dean', ['college_id' => $college->id]);
        $this->actingAs($dean)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Looks good.'])
            ->assertCreated()
            ->assertJsonPath('data.authorRole', 'Dean');

        $otherCollege = College::factory()->create();
        $outsideDean = $this->userWithRole('dean', ['college_id' => $otherCollege->id]);
        $this->actingAs($outsideDean)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Should fail'])
            ->assertForbidden();
    }

    public function test_program_chair_can_comment_within_their_program_but_not_outside_it(): void
    {
        ['program' => $program, 'row' => $row] = $this->makeAreaWithRow();

        $chair = $this->userWithRole('program-chair');
        $program->update(['chair_id' => $chair->id]);

        $this->actingAs($chair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Approved direction.'])
            ->assertCreated()
            ->assertJsonPath('data.authorRole', 'Program Chair');

        $outsideChair = $this->userWithRole('program-chair');
        $this->actingAs($outsideChair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Should fail'])
            ->assertForbidden();
    }

    public function test_institution_wide_roles_can_comment_on_any_row(): void
    {
        ['row' => $row] = $this->makeAreaWithRow();

        foreach (['accreditor', 'qa', 'vpaa'] as $role) {
            $user = $this->userWithRole($role);

            $this->actingAs($user)
                ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => "Comment from {$role}"])
                ->assertCreated();
        }
    }

    public function test_commenting_does_not_change_area_progress(): void
    {
        ['area' => $area, 'row' => $row] = $this->makeAreaWithRow();

        $chair = User::factory()->create();
        $area->update(['chair_id' => $chair->id, 'progress_percent' => 42]);

        $this->actingAs($chair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Just a note.'])
            ->assertCreated();

        $this->assertSame(42, $area->fresh()->progress_percent);
    }

    public function test_return_persists_a_comment_and_plain_comments_never_change_document_status(): void
    {
        Notification::fake();

        ['area' => $area, 'row' => $row, 'program' => $program] = $this->makeAreaWithRow();

        $chair = User::factory()->create();
        $area->update(['chair_id' => $chair->id]);

        $programChair = $this->userWithRole('program-chair');
        $program->update(['chair_id' => $programChair->id]);

        $document = Document::factory()->create([
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'status' => 'Active',
        ]);

        $this->actingAs($programChair)
            ->postJson("/api/documents/{$document->id}/request-revision", ['comment' => 'Please add a rubric.'])
            ->assertOk();

        $document->refresh();
        $this->assertSame('Revision Requested', $document->status);

        $this->assertDatabaseHas('parameter_row_comments', [
            'content_row_id' => $row->id,
            'document_id' => $document->id,
            'source' => 'revision_request',
            'body' => 'Please add a rubric.',
        ]);

        // A plain reply afterwards must not touch the document's status.
        $this->actingAs($chair)
            ->postJson("/api/parameter-rows/{$row->id}/comments", ['body' => 'Feedback addressed.'])
            ->assertCreated();

        $document->refresh();
        $this->assertSame('Revision Requested', $document->status);
        $this->assertSame(2, ParameterRowComment::where('content_row_id', $row->id)->count());
    }
}
