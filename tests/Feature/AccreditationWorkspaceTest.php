<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use Database\Seeders\InstrumentTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccreditationWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $programChair;
    private User $faculty;
    private Program $program;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
        $this->seed(InstrumentTemplateSeeder::class);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);
        $this->programChair = User::factory()->create([
            'college_id' => $college->id,
            'program_id' => $this->program->id,
        ]);
        $this->programChair->assignRole('Program Chair');
        $this->program->update(['chair_id' => $this->programChair->id]);

        $this->faculty = User::factory()->create([
            'college_id' => $college->id,
            'program_id' => $this->program->id,
        ]);
        $this->faculty->assignRole('Faculty');
    }

    public function test_vpaa_can_save_template(): void
    {
        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');

        $this->actingAs($vpaa, 'sanctum')
            ->postJson('/api/instrument-templates', [
                'name' => 'Custom Level I',
                'level' => 'Level I',
                'areas' => [
                    [
                        'name' => 'Area I: Vision',
                        'parameters' => [
                            [
                                'code' => 'A',
                                'name' => 'Parameter A',
                                'criteria' => [
                                    ['title' => 'Vision statement', 'evidence_type' => 'system'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_program_chair_creates_folder_from_template_and_assigns_faculty(): void
    {
        $create = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-workspaces', [
                'level' => 'Level I',
                'deadline' => '2026-12-01',
            ])
            ->assertCreated();

        $workspaceId = $create->json('data.id');
        $this->assertNotEmpty($create->json('data.areas'));
        $areaId = $create->json('data.areas.0.id');

        $this->actingAs($this->programChair, 'sanctum')
            ->postJson("/api/accreditation-workspaces/{$workspaceId}/areas/{$areaId}/chair", [
                'chair_id' => $this->faculty->id,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->actingAs($this->faculty, 'sanctum')
            ->getJson('/api/accreditation-workspaces')
            ->assertOk()
            ->assertJsonPath('data.0.areas.0.chair.id', $this->faculty->id);
    }

    public function test_faculty_can_attach_file_and_mark_criterion_done(): void
    {
        $workspace = $this->actingAs($this->programChair, 'sanctum')
            ->postJson('/api/accreditation-workspaces', [
                'level' => 'Level I',
                'deadline' => '2026-11-30',
            ])
            ->json('data');

        $areaId = $workspace['areas'][0]['id'];
        $this->actingAs($this->programChair, 'sanctum')
            ->postJson("/api/accreditation-workspaces/{$workspace['id']}/areas/{$areaId}/chair", [
                'chair_id' => $this->faculty->id,
            ]);

        $criterionId = $workspace['areas'][0]['parameters'][0]['criteria'][0]['id'];

        $this->actingAs($this->faculty, 'sanctum')
            ->post("/api/accreditation-workspaces/{$workspace['id']}/criteria/{$criterionId}/evidence", [
                'file' => UploadedFile::fake()->create('evidence.pdf', 100, 'application/pdf'),
            ])
            ->assertOk();

        $this->actingAs($this->faculty, 'sanctum')
            ->postJson("/api/accreditation-workspaces/{$workspace['id']}/criteria/{$criterionId}/done")
            ->assertOk();
    }

    public function test_program_chair_cannot_use_another_program_area(): void
    {
        $other = Program::factory()->create();
        $otherChair = User::factory()->create(['program_id' => $other->id]);
        $otherChair->assignRole('Program Chair');
        $other->update(['chair_id' => $otherChair->id]);

        $workspace = $this->actingAs($otherChair, 'sanctum')
            ->postJson('/api/accreditation-workspaces', ['level' => 'Level I'])
            ->json('data');

        $this->actingAs($this->programChair, 'sanctum')
            ->getJson('/api/accreditation-workspaces/'.$workspace['id'])
            ->assertStatus(403);
    }
}
