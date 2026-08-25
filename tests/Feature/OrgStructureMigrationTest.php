<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\OrgScope;
use App\Support\RoleSlug;
use Database\Seeders\OrgStructureSeeder;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgStructureMigrationTest extends TestCase
{
    public function test_role_slugs_canonicalize_legacy_names(): void
    {
        $this->assertSame(RoleSlug::DEAN, RoleSlug::canonicalize('Dean'));
        $this->assertSame(RoleSlug::PROGRAM_CHAIR, RoleSlug::canonicalize('Program Chair'));
        $this->assertSame(RoleSlug::AREA_IN_CHARGE, RoleSlug::canonicalize('Area In-Charge'));
        $this->assertSame(RoleSlug::AREA_IN_CHARGE, RoleSlug::canonicalize('area-incharge'));
        $this->assertSame(RoleSlug::SUPERADMIN, RoleSlug::canonicalize('Super Administrator'));
        $this->assertSame(RoleSlug::VPAA, RoleSlug::canonicalize('vpaa-di'));
        $this->assertSame(RoleSlug::QA, RoleSlug::canonicalize('QA'));
    }

    public function test_dean_college_id_is_required_and_not_inferred(): void
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $dean = User::factory()->create(['program_id' => $program->id, 'college_id' => null]);
        $dean->assignRole(RoleSlug::DEAN);

        $this->assertNull($dean->getEffectiveCollegeId());
        $this->assertSame([], OrgScope::visibleProgramIds($dean->fresh()));
    }

    public function test_second_dean_for_the_same_college_is_rejected(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleSlug::SUPERADMIN);
        $college = College::factory()->create();
        $existing = User::factory()->create(['college_id' => $college->id]);
        $existing->assignRole(RoleSlug::DEAN);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/users', [
            'first_name' => 'Second',
            'last_name' => 'Dean',
            'email' => 'second-dean@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'dean',
            'college_id' => $college->id,
        ])->assertStatus(422);
    }

    public function test_program_chair_creation_requires_a_program_and_rejects_double_chairing(): void
    {
        $admin = User::factory()->create();
        $admin->assignRole(RoleSlug::SUPERADMIN);
        $college = College::factory()->create();
        $programA = Program::factory()->create(['college_id' => $college->id, 'chair_id' => null]);
        $programB = Program::factory()->create(['college_id' => $college->id, 'chair_id' => null]);

        Sanctum::actingAs($admin);
        $this->postJson('/api/admin/users', [
            'first_name' => 'Pat',
            'last_name' => 'Chair',
            'email' => 'chair-no-program@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'program-chair',
        ])->assertStatus(422);

        $create = $this->postJson('/api/admin/users', [
            'first_name' => 'Pat',
            'last_name' => 'Chair',
            'email' => 'chair-with-program@example.test',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role' => 'program-chair',
            'program_id' => $programA->id,
        ]);
        $create->assertStatus(201);
        $chairId = $create->json('data.id');
        $this->assertSame($chairId, $programA->fresh()->chair_id);

        $this->putJson('/api/programs/'.$programB->id, [
            'chair_id' => $chairId,
        ])->assertStatus(422);
    }

    public function test_faculty_membership_is_users_program_id_only(): void
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $faculty = User::factory()->create(['program_id' => $program->id]);
        $faculty->assignRole(RoleSlug::FACULTY);
        $outsider = User::factory()->create(['program_id' => null]);
        $outsider->assignRole(RoleSlug::FACULTY);

        $this->assertTrue($faculty->belongsToProgram($program->id));
        $this->assertFalse($outsider->belongsToProgram($program->id));
        $this->assertSame([$program->id], OrgScope::visibleProgramIds($faculty));
    }

    public function test_qa_dashboard_and_unscoped_cycle_index_are_role_gated(): void
    {
        $college = College::factory()->create();
        $visible = Program::factory()->create(['college_id' => $college->id]);
        $hiddenCollege = College::factory()->create();
        Program::factory()->create(['college_id' => $hiddenCollege->id]);

        $qa = User::factory()->create(['college_id' => null, 'program_id' => null]);
        $qa->assignRole(RoleSlug::QA);
        Sanctum::actingAs($qa);
        $this->getJson('/api/qa/dashboard')->assertOk();
        $this->getJson('/api/accreditation-cycles')->assertOk();

        $dean = User::factory()->create(['college_id' => $college->id]);
        $dean->assignRole(RoleSlug::DEAN);
        Sanctum::actingAs($dean);
        $cycles = $this->getJson('/api/accreditation-cycles')->assertOk();
        $programIds = collect($cycles->json('data'))->pluck('program_id')->filter()->unique();
        $this->assertTrue($programIds->every(fn ($id) => (int) $id === (int) $visible->id) || $programIds->isEmpty());
    }

    public function test_user_resource_program_id_is_the_stored_fk(): void
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $user = User::factory()->create(['program_id' => $program->id, 'college_id' => $college->id]);
        $user->assignRole(RoleSlug::FACULTY);
        Sanctum::actingAs($user);

        $me = $this->getJson('/api/me')->assertOk();
        $this->assertSame($program->id, (int) $me->json('data.user.program_id'));
        $this->assertSame($college->id, (int) $me->json('data.user.college_id'));
    }

    public function test_qa_can_view_structure_and_dean_is_college_scoped(): void
    {
        $college = College::factory()->create();
        $otherCollege = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $otherProgram = Program::factory()->create(['college_id' => $otherCollege->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $otherCycle = AccreditationCycle::factory()->create([
            'program_id' => $otherProgram->id,
            'college_id' => $otherCollege->id,
        ]);

        $qa = User::factory()->create(['college_id' => null, 'program_id' => null]);
        $qa->assignRole(RoleSlug::QA);
        Sanctum::actingAs($qa);
        $this->getJson('/api/accreditation-cycles/'.$cycle->id.'/structure')->assertOk();

        $dean = User::factory()->create(['college_id' => $college->id]);
        $dean->assignRole(RoleSlug::DEAN);
        Sanctum::actingAs($dean);
        $this->getJson('/api/accreditation-cycles/'.$cycle->id.'/structure')->assertOk();
        $this->getJson('/api/accreditation-cycles/'.$otherCycle->id.'/structure')->assertForbidden();
    }

    public function test_area_in_charge_can_load_the_scoped_dashboard(): void
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $user = User::factory()->create(['program_id' => $program->id, 'college_id' => $college->id]);
        $user->assignRole(RoleSlug::AREA_IN_CHARGE);
        Sanctum::actingAs($user);

        $this->getJson('/api/dashboard')->assertOk();
        $this->getJson('/api/admin/dashboard')->assertForbidden();
    }

    public function test_org_structure_seeder_creates_iof_and_bsfas(): void
    {
        $this->seed(OrgStructureSeeder::class);

        $this->assertDatabaseHas('colleges', [
            'code' => OrgStructureSeeder::IOF_CODE,
            'name' => 'Institute of Fisheries',
            'campus' => 'Echague Main Campus',
        ]);
        $this->assertDatabaseHas('programs', [
            'code' => OrgStructureSeeder::BSFAS_CODE,
            'name' => 'Bachelor of Science in Fisheries and Aquatic Sciences',
        ]);
        $this->assertSame(1, College::count());
        $this->assertSame(1, Program::count());
        $this->assertSame(1, AccreditationCycle::count());
        $this->assertSame(10, AccreditationArea::count());
        $this->assertDatabaseHas('accreditation_cycles', ['level' => 'Level I']);
        $this->assertDatabaseMissing('accreditation_cycles', ['level' => 'Level II']);
        $this->assertDatabaseMissing('accreditation_cycles', ['level' => 'Level III']);
        $this->assertSame(
            10,
            AccreditationArea::query()->where('cycle_id', AccreditationCycle::query()->where('level', 'Level I')->value('id'))->count()
        );
        $this->assertGreaterThanOrEqual(2, \App\Models\AccreditationParameter::query()->count());
        $this->assertSame(10, \App\Models\InstrumentTemplateArea::query()
            ->where('template_id', \App\Models\InstrumentTemplate::query()->where('level', 'Level I')->value('id'))
            ->count());
    }

    public function test_prune_keeps_only_iof_and_moves_scoped_users(): void
    {
        $other = College::factory()->create(['name' => 'College of Computing']);
        $otherProgram = Program::factory()->create(['college_id' => $other->id, 'code' => 'BSIT']);
        $dean = User::factory()->create(['college_id' => $other->id]);
        $dean->assignRole(RoleSlug::DEAN);
        $chair = User::factory()->create(['college_id' => $other->id, 'program_id' => $otherProgram->id]);
        $chair->assignRole(RoleSlug::PROGRAM_CHAIR);
        $otherProgram->update(['chair_id' => $chair->id]);

        $removed = (new OrgStructureSeeder())->pruneOtherUnits();

        $this->assertSame(1, $removed);
        $this->assertSame(1, College::count());
        $this->assertSame(1, Program::count());
        $this->assertDatabaseHas('programs', ['code' => OrgStructureSeeder::BSFAS_CODE]);
        $this->assertDatabaseMissing('colleges', ['id' => $other->id]);
        $this->assertSame(College::query()->where('code', 'IOF')->value('id'), $dean->fresh()->college_id);
        $this->assertSame(Program::query()->where('code', 'BSFAS')->value('id'), $chair->fresh()->program_id);
        $this->assertSame($chair->id, Program::query()->where('code', 'BSFAS')->value('chair_id'));
    }
}
