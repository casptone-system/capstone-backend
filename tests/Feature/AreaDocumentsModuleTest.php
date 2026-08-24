<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AreaDocumentsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $chair;

    private Program $program;

    private AccreditationCycle $cycle;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $this->program = Program::factory()->create(['college_id' => $college->id]);

        $this->chair = User::factory()->create();
        $this->chair->assignRole('Program Chair');
        $this->chair->program_id = $this->program->id;
        $this->chair->save();

        $this->program->chair_id = $this->chair->id;
        $this->program->save();

        $this->cycle = AccreditationCycle::factory()->create([
            'program_id' => $this->program->id,
            'level' => 'Level I',
            'status' => 'Preparation',
        ]);

        Sanctum::actingAs($this->chair);
    }

    public function test_program_chair_area_documents_returns_levels_areas_and_review_status(): void
    {
        $this->getJson('/api/program-chair/areas')->assertStatus(200);

        $area = AccreditationArea::where('cycle_id', $this->cycle->id)->where('code', 'area-1')->firstOrFail();
        $areaChair = User::factory()->create();
        $areaChair->assignRole('Area In-Charge');
        $area->update(['chair_id' => $areaChair->id, 'status' => 'In Progress']);

        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');
        Document::factory()->create([
            'program_id' => $this->program->id,
            'area_id' => $area->id,
            'uploaded_by' => $faculty->id,
        ]);

        $review = Review::factory()->create([
            'area_id' => $area->id,
            'cycle_id' => $this->cycle->id,
            'submitted_by' => $faculty->id,
            'current_status' => 'Submitted',
            'submitted_at' => now(),
        ]);

        $response = $this->getJson('/api/program-chair/area-documents');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.programId', $this->program->id);

        $levels = collect($response->json('data.levels'));
        $this->assertEquals(['Level I', 'Level II', 'Level III', 'Level IV'], $levels->pluck('level')->all());
        $this->assertCount(3, $levels->whereNull('cycleId'));

        $levelI = $levels->firstWhere('level', 'Level I');
        $this->assertSame($this->cycle->id, $levelI['cycleId']);
        $this->assertSame(1, $levelI['documentCount']);
        $this->assertCount(10, $levelI['areas']);

        $areaPayload = collect($levelI['areas'])->firstWhere('code', 'area-1');
        $this->assertSame($areaChair->id, $areaPayload['chair']['id']);
        $this->assertSame('In Progress', $areaPayload['status']);
        $this->assertSame(1, $areaPayload['documentCount']);
        $this->assertSame($review->id, $areaPayload['review']['id']);
        $this->assertSame('Submitted', $areaPayload['review']['currentStatus']);
        $this->assertFalse($areaPayload['review']['canApprove']);
        $this->assertFalse($areaPayload['review']['canRequestRevision']);
    }

    public function test_unrelated_user_cannot_list_area_documents(): void
    {
        $intruder = User::factory()->create();
        Sanctum::actingAs($intruder);

        $this->getJson('/api/program-chair/area-documents')->assertStatus(403);
    }

    public function test_faculty_can_list_area_documents_locked_to_the_active_level(): void
    {
        $this->program->update(['active_cycle_id' => $this->cycle->id]);

        $faculty = User::factory()->create(['program_id' => $this->program->id]);
        $faculty->assignRole('Faculty');
        Sanctum::actingAs($faculty);

        $this->getJson('/api/program-chair/area-documents')
            ->assertStatus(200)
            ->assertJsonPath('data.lockedToActiveLevel', true)
            ->assertJsonPath('data.activeCycleId', $this->cycle->id);
    }
}
