<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\College;
use App\Models\LevelParameterRow;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LevelInstrumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_cannot_edit_level_instruments(): void
    {
        $faculty = $this->userWithRole(RoleSlug::FACULTY);

        $this->actingAs($faculty)
            ->getJson('/api/vpaa/instrument/areas?level=Level%20I')
            ->assertForbidden();
    }

    public function test_each_level_keeps_its_own_parameter_text_and_publishes_only_to_that_level(): void
    {
        $vpaa = $this->userWithRole(RoleSlug::VPAA);
        $levelOne = $this->programArea('Level I', 'Level I original');
        $levelTwo = $this->programArea('Level II', 'Level II original');

        $this->actingAs($vpaa)
            ->getJson('/api/vpaa/instrument/areas/area-1/parameters/A/rows?level=Level%20I')
            ->assertOk();

        $levelOneRow = LevelParameterRow::query()
            ->where('level', 'Level I')
            ->where('area_code', 'area-1')
            ->where('parameter_code', 'A')
            ->orderBy('sort_order')
            ->first();

        $this->assertNotNull($levelOneRow);

        $this->actingAs($vpaa)
            ->patchJson('/api/vpaa/instrument/rows/'.$levelOneRow->id, [
                'content' => 'Custom Level 1 statement',
            ])
            ->assertOk()
            ->assertJsonPath('data.content', 'Custom Level 1 statement');

        $this->assertSame(
            'Custom Level 1 statement',
            ParameterContentRow::query()->where('parameter_id', $levelOne['parameter']->id)->orderBy('sort_order')->value('content')
        );

        $this->assertSame(
            'Level II original',
            ParameterContentRow::query()->where('parameter_id', $levelTwo['parameter']->id)->value('content')
        );

        $levelTwoFirst = LevelParameterRow::query()
            ->where('level', 'Level II')
            ->where('area_code', 'area-1')
            ->where('parameter_code', 'A')
            ->orderBy('sort_order')
            ->value('content');

        $this->assertNotSame('Custom Level 1 statement', $levelTwoFirst);
    }

    /**
     * @return array{parameter: AccreditationParameter}
     */
    private function programArea(string $level, string $content): array
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => $level,
        ]);
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1 – Vision, Mission, Goals and Objectives',
        ]);
        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'A',
            'name' => 'STATEMENT OF VISION, MISSION, GOALS AND OBJECTIVES',
            'sort_order' => 0,
        ]);
        ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => $content,
            'sort_order' => 0,
        ]);

        return ['parameter' => $parameter];
    }

    private function userWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }
}
