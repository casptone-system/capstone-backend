<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\BoardOfActionDocument;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\User;
use App\Notifications\BoardOfActionUploadedNotification;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class BoardOfActionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_qa_can_upload_a_pdf_without_touching_area_documents(): void
    {
        [$program, $chair] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);

        $response = $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'title' => 'BOR-2026-04',
            'notes' => 'Compliance items for the next accreditation.',
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonPath('data.title', 'BOR-2026-04');
        $response->assertJsonPath('data.programId', $program->id);
        $response->assertJsonMissingPath('data.filePath');

        $this->assertSame(1, BoardOfActionDocument::count());
        $this->assertSame(0, Document::count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'board_of_action_uploaded',
            'user_id' => $qa->id,
            'status' => 'success',
        ]);

        Notification::assertSentTo($chair, BoardOfActionUploadedNotification::class, function (BoardOfActionUploadedNotification $notification, array $channels) {
            return $notification->replaced === false
                && in_array('mail', $channels, true)
                && in_array('database', $channels, true);
        });
    }

    public function test_superadmin_can_upload_as_an_override(): void
    {
        [$program] = $this->programWithChair();
        $admin = $this->userWithRole(RoleSlug::SUPERADMIN);

        $this->act($admin)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();
    }

    public function test_program_chair_can_view_and_download_only_their_program(): void
    {
        [$program, $chair] = $this->programWithChair();
        [$otherProgram, $otherChair] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);

        $uploaded = $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'title' => 'Resolution 1',
            'file' => $this->pdf('resolution.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $id = $uploaded->json('data.id');

        $this->act($chair)->getJson('/api/board-of-action/mine')
            ->assertOk()
            ->assertJsonPath('data.programs.0.id', $program->id)
            ->assertJsonPath('data.programs.0.documents.0.id', $id);

        $this->act($chair)->getJson("/api/board-of-action/{$id}")
            ->assertOk()
            ->assertJsonPath('data.displayTitle', 'Resolution 1');

        $this->act($chair)->get("/api/board-of-action/{$id}/download")->assertOk();
        $this->act($chair)->get("/api/board-of-action/{$id}/preview")->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'board_of_action_viewed',
            'user_id' => $chair->id,
            'status' => 'success',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'board_of_action_downloaded',
            'user_id' => $chair->id,
            'status' => 'success',
        ]);

        $this->act($otherChair)->getJson("/api/board-of-action/{$id}")->assertForbidden();
        $this->act($otherChair)->getJson("/api/board-of-action/{$id}/download")->assertForbidden();
        $this->act($otherChair)->getJson("/api/board-of-action/{$id}/preview")->assertForbidden();
        $this->act($otherChair)->getJson('/api/board-of-action/mine')
            ->assertOk()
            ->assertJsonPath('data.programs.0.id', $otherProgram->id)
            ->assertJsonPath('data.programs.0.documents', []);

        $this->act($chair)->getJson("/api/documents/{$id}")->assertNotFound();
    }

    public function test_other_roles_cannot_view_or_download(): void
    {
        [$program, , $college] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);
        $id = $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $roles = [
            RoleSlug::DEAN,
            RoleSlug::FACULTY,
            RoleSlug::AREA_IN_CHARGE,
            RoleSlug::VPAA,
            RoleSlug::ACCREDITOR,
        ];

        foreach ($roles as $role) {
            $user = $this->userWithRole($role);
            $user->update([
                'college_id' => $college->id,
                'program_id' => $program->id,
            ]);

            $this->act($user)->getJson('/api/board-of-action/programs')->assertForbidden();
            $this->act($user)->getJson('/api/board-of-action/mine')->assertForbidden();
            $this->act($user)->getJson("/api/board-of-action/{$id}")->assertForbidden();
            $this->act($user)->getJson("/api/board-of-action/{$id}/download")->assertForbidden();
            $this->act($user)->getJson("/api/board-of-action/{$id}/preview")->assertForbidden();
            $this->act($user)->deleteJson("/api/board-of-action/{$id}")->assertForbidden();
        }

        $this->assertNotNull(BoardOfActionDocument::find($id));
    }

    public function test_chairless_program_is_missing_until_a_file_exists_and_has_no_viewer(): void
    {
        $college = College::factory()->create(['name' => 'Institute of Fisheries']);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Fisheries',
            'chair_id' => null,
        ]);
        $qa = $this->userWithRole(RoleSlug::QA);
        $unassignedChair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $unassignedChair->update(['program_id' => $program->id, 'college_id' => $college->id]);

        $this->act($qa)->getJson('/api/board-of-action/programs')
            ->assertOk()
            ->assertJsonPath('data.0.collegeName', 'Institute of Fisheries')
            ->assertJsonPath('data.0.programs.0.status', 'missing')
            ->assertJsonPath('data.0.programs.0.onFileDate', null);

        $uploaded = $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'file' => $this->pdf(),
        ], ['Accept' => 'application/json'])->assertCreated();

        Notification::assertNothingSent();

        $this->act($qa)->getJson('/api/board-of-action/programs')
            ->assertOk()
            ->assertJsonPath('data.0.programs.0.status', 'on_file')
            ->assertJsonPath('data.0.programs.0.onFileDate', now()->toDateString());

        $this->act($unassignedChair)->getJson('/api/board-of-action/mine')
            ->assertOk()
            ->assertJsonPath('data.programs', []);

        $this->act($unassignedChair)
            ->getJson('/api/board-of-action/'.$uploaded->json('data.id'))
            ->assertForbidden();
    }

    public function test_programs_are_grouped_newest_first_and_can_be_filtered_to_missing(): void
    {
        $college = College::factory()->create(['name' => 'College of Agriculture']);
        $older = Program::factory()->create(['college_id' => $college->id, 'name' => 'Agriculture']);
        $newerHome = Program::factory()->create(['college_id' => $college->id, 'name' => 'Forestry']);
        $empty = Program::factory()->create([
            'college_id' => College::factory()->create(['name' => 'College of Education'])->id,
            'name' => 'Education',
        ]);
        $qa = $this->userWithRole(RoleSlug::QA);

        $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $newerHome->id,
            'title' => 'Older upload',
            'file' => $this->pdf('older.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $newerHome->id,
            'title' => 'Newer upload',
            'file' => $this->pdf('newer.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $listed = $this->act($qa)->getJson('/api/board-of-action/programs?search=College')
            ->assertOk()
            ->json('data');

        $agriculture = collect($listed)->firstWhere('collegeName', 'College of Agriculture');
        $forestry = collect($agriculture['programs'])->firstWhere('name', 'Forestry');
        $this->assertSame('Newer upload', $forestry['documents'][0]['title']);
        $this->assertSame('Older upload', $forestry['documents'][1]['title']);
        $this->assertSame('on_file', $forestry['status']);
        $this->assertSame(now()->toDateString(), $forestry['onFileDate']);

        $missing = $this->act($qa)->getJson('/api/board-of-action/programs?missing=1')
            ->assertOk()
            ->json('data');

        $names = collect($missing)->flatMap(fn (array $college) => collect($college['programs'])->pluck('name'))->all();
        $this->assertContains('Education', $names);
        $this->assertContains('Agriculture', $names);
        $this->assertNotContains('Forestry', $names);
        $this->assertNotNull($older);
        $this->assertNotNull($empty);
    }

    public function test_replace_keeps_the_previous_version_and_delete_removes_the_document(): void
    {
        [$program, $chair] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);

        $id = $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'title' => 'First',
            'file' => $this->pdf('first.pdf'),
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.id');

        $replaced = $this->act($qa)->post("/api/board-of-action/{$id}/replace", [
            'title' => 'Second',
            'file' => $this->pdf('second.pdf'),
        ], ['Accept' => 'application/json']);

        $replaced->assertOk();
        $replaced->assertJsonPath('data.currentVersion', 2);
        $replaced->assertJsonPath('data.originalName', 'second.pdf');
        $this->assertSame(2, BoardOfActionDocument::find($id)->versions()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'board_of_action_replaced',
            'user_id' => $qa->id,
            'status' => 'success',
        ]);
        Notification::assertSentToTimes($chair, BoardOfActionUploadedNotification::class, 2);

        $this->act($chair)->deleteJson("/api/board-of-action/{$id}")->assertForbidden();
        $this->act($qa)->deleteJson("/api/board-of-action/{$id}")->assertOk();
        $this->assertNull(BoardOfActionDocument::find($id));
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'board_of_action_deleted',
            'user_id' => $qa->id,
            'status' => 'success',
        ]);
    }

    public function test_only_pdfs_within_the_upload_limit_are_accepted(): void
    {
        [$program] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);

        $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'file' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        config(['filesystems.document_upload_max_kb' => 1]);

        $this->act($qa)->post('/api/board-of-action', [
            'program_id' => $program->id,
            'file' => UploadedFile::fake()->create('big.pdf', 3, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, BoardOfActionDocument::count());
        $this->assertSame(0, AuditLog::where('event', 'board_of_action_uploaded')->where('status', 'success')->count());
    }

    private function act(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /**
     * @return array{0: Program, 1: User, 2: College}
     */
    private function programWithChair(): array
    {
        $college = College::factory()->create();
        $chair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'chair_id' => $chair->id,
        ]);
        $chair->update(['program_id' => $program->id, 'college_id' => $college->id]);

        return [$program, $chair, $college];
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function pdf(string $name = 'board-of-action.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF\n");
    }
}
