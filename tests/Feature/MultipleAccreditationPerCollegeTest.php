<?php

namespace Tests\Feature;

use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Support\ActiveCycle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Multiple accreditation for a single department/college" verification.
 *
 * Confirmed interpretation: multiple PROGRAMS within one College can each
 * pursue their own accreditation independently and simultaneously, each
 * with its own active_cycle_id. This is not new functionality — this test
 * verifies the existing data model and ActiveCycle helper already support
 * it, and guards against regressions.
 */
class MultipleAccreditationPerCollegeTest extends TestCase
{
    use RefreshDatabase;

    public function test_programs_in_the_same_college_hold_independent_active_levels(): void
    {
        $college = College::factory()->create();

        $programA = Program::factory()->create(['college_id' => $college->id]);
        $programB = Program::factory()->create(['college_id' => $college->id]);

        $cycleA = AccreditationCycle::factory()->create([
            'program_id' => $programA->id,
            'college_id' => $college->id,
            'level' => 'Preliminary',
        ]);

        $cycleB = AccreditationCycle::factory()->create([
            'program_id' => $programB->id,
            'college_id' => $college->id,
            'level' => 'Level II',
        ]);

        $programA->update(['active_cycle_id' => $cycleA->id, 'accreditation_level' => 'Preliminary']);
        $programB->update(['active_cycle_id' => $cycleB->id, 'accreditation_level' => 'Level II']);

        $programA->refresh();
        $programB->refresh();

        // Each program keeps its own active level independently, in the same college.
        $this->assertSame('Preliminary', $programA->activeCycle->level);
        $this->assertSame('Level II', $programB->activeCycle->level);
        $this->assertNotEquals($programA->active_cycle_id, $programB->active_cycle_id);

        // ActiveCycle::uniquePerProgram resolves one correct cycle per program,
        // not one shared "college-wide" cycle.
        $allCycles = AccreditationCycle::query()
            ->whereIn('program_id', [$programA->id, $programB->id])
            ->with('program')
            ->get();

        $resolved = ActiveCycle::uniquePerProgram($allCycles)->keyBy('program_id');

        $this->assertCount(2, $resolved);
        $this->assertSame($cycleA->id, $resolved->get($programA->id)?->id);
        $this->assertSame($cycleB->id, $resolved->get($programB->id)?->id);
    }

    public function test_setting_one_programs_active_level_does_not_affect_a_sibling_program(): void
    {
        $college = College::factory()->create();

        $programA = Program::factory()->create(['college_id' => $college->id]);
        $programB = Program::factory()->create(['college_id' => $college->id]);

        $cycleB = AccreditationCycle::factory()->create([
            'program_id' => $programB->id,
            'college_id' => $college->id,
            'level' => 'Level I',
        ]);
        $programB->update(['active_cycle_id' => $cycleB->id, 'accreditation_level' => 'Level I']);

        $cycleA1 = AccreditationCycle::factory()->create([
            'program_id' => $programA->id,
            'college_id' => $college->id,
            'level' => 'Preliminary',
        ]);
        $cycleA2 = AccreditationCycle::factory()->create([
            'program_id' => $programA->id,
            'college_id' => $college->id,
            'level' => 'Level I',
        ]);

        $programA->update(['active_cycle_id' => $cycleA1->id, 'accreditation_level' => 'Preliminary']);
        $programB->refresh();
        $this->assertSame($cycleB->id, $programB->active_cycle_id);

        $programA->update(['active_cycle_id' => $cycleA2->id, 'accreditation_level' => 'Level I']);
        $programB->refresh();

        // Program B's active cycle is untouched by Program A's level changes.
        $this->assertSame($cycleB->id, $programB->active_cycle_id);
        $this->assertSame('Level I', $programB->activeCycle->level);
    }
}
