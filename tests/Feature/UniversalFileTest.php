<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\Program;
use App\Models\UniversalFile;
use App\Models\User;
use App\Notifications\UniversalFileUploadedNotification;
use App\Support\RoleSlug;
use App\Support\UniversalFileRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UniversalFileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_qa_can_upload_shared_pdfs_without_creating_area_documents(): void
    {
        [, $chair] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);

        $response = $this->act($qa)->post('/api/universal-files', [
            'area_code' => 'area-2',
            'title' => 'Institution calendar',
            'notes' => 'Use this in every college.',
            'files' => [$this->pdf('calendar.pdf'), $this->pdf('annex.pdf')],
        ], ['Accept' => 'application/json']);

        $response->assertCreated();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.areaCode', 'area-2');
        $response->assertJsonPath('data.0.areaName', 'Area 2 – Faculty');
        $response->assertJsonMissingPath('data.0.filePath');
        $this->assertSame(2, UniversalFile::count());
        $this->assertSame(0, Document::count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'universal_file_uploaded',
            'user_id' => $qa->id,
            'status' => 'success',
        ]);
        Notification::assertSentTo($chair, UniversalFileUploadedNotification::class);

        $id = $response->json('data.0.id');
        $list = $this->act($chair)->getJson('/api/universal-files')->assertOk();
        $list->assertJsonFragment(['id' => $id, 'areaCode' => 'area-2']);
        $list->assertJsonFragment(['code' => 'area-2', 'name' => 'Area 2 – Faculty']);
        $this->act($chair)->get("/api/universal-files/{$id}/preview")->assertOk();
        $this->act($chair)->get("/api/universal-files/{$id}/download")->assertOk();
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'universal_file_downloaded',
            'user_id' => $chair->id,
            'status' => 'success',
        ]);

        $this->act($chair)->deleteJson("/api/universal-files/{$id}")->assertForbidden();
        $this->act($qa)->deleteJson("/api/universal-files/{$id}")->assertOk();
        $this->assertNull(UniversalFile::find($id));
    }

    public function test_only_pdfs_within_the_batch_limit_are_accepted(): void
    {
        $qa = $this->userWithRole(RoleSlug::QA);

        $this->act($qa)->post('/api/universal-files', [
            'area_code' => 'area-2',
            'files' => [UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->act($qa)->post('/api/universal-files', [
            'area_code' => 'not-an-area',
            'files' => [$this->pdf()],
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $tooMany = [];
        for ($i = 0; $i < UniversalFileRules::MAX_FILES + 1; $i++) {
            $tooMany[] = $this->pdf('extra-'.$i.'.pdf');
        }
        $this->act($qa)->post('/api/universal-files', [
            'area_code' => 'general',
            'files' => $tooMany,
        ], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame(0, UniversalFile::count());
    }

    public function test_other_roles_cannot_view_or_upload_universal_files(): void
    {
        [, , $college] = $this->programWithChair();
        $qa = $this->userWithRole(RoleSlug::QA);
        $id = $this->act($qa)->post('/api/universal-files', [
            'area_code' => 'general',
            'files' => [$this->pdf()],
        ], ['Accept' => 'application/json'])->assertCreated()->json('data.0.id');

        $unassigned = $this->userWithRole(RoleSlug::PROGRAM_CHAIR);
        $this->act($unassigned)->getJson('/api/universal-files')->assertForbidden();

        foreach ([RoleSlug::DEAN, RoleSlug::FACULTY, RoleSlug::VPAA, RoleSlug::ACCREDITOR] as $role) {
            $user = $this->userWithRole($role);
            $user->update(['college_id' => $college->id]);
            $this->act($user)->getJson('/api/universal-files')->assertForbidden();
            $this->act($user)->get("/api/universal-files/{$id}/download")->assertForbidden();
            $this->act($user)->post('/api/universal-files', [
                'area_code' => 'area-1',
                'files' => [$this->pdf()],
            ], ['Accept' => 'application/json'])->assertForbidden();
        }
    }

    private function act(User $user): static
    {
        $this->app['auth']->forgetGuards();

        return $this->actingAs($user);
    }

    /**
     * @return array{0: Program, 1: User, 2: \App\Models\College}
     */
    private function programWithChair(): array
    {
        $college = \App\Models\College::factory()->create();
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

    private function pdf(string $name = 'universal.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($name, 12, 'application/pdf');
    }
}
