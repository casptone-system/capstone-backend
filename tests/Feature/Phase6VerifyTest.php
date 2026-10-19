<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\OrgScope;
use App\Support\RoleSlug;
use App\Support\ScopedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase6VerifyTest extends TestCase
{
    use RefreshDatabase;

    public function test_org_scope_hides_other_college_programs_from_dean(): void
    {
        Role::findOrCreate(RoleSlug::DEAN, 'web');
        Role::findOrCreate(RoleSlug::FACULTY, 'web');

        $collegeA = College::factory()->create();
        $collegeB = College::factory()->create();
        $programA = Program::factory()->create(['college_id' => $collegeA->id]);
        $programB = Program::factory()->create(['college_id' => $collegeB->id]);

        $dean = User::factory()->create(['college_id' => $collegeA->id]);
        $dean->assignRole(RoleSlug::DEAN);

        $this->assertTrue(OrgScope::canSeeProgram($dean, (int) $programA->id));
        $this->assertFalse(OrgScope::canSeeProgram($dean, (int) $programB->id));

        $ids = OrgScope::visibleProgramIds($dean);
        $this->assertIsArray($ids);
        $this->assertContains((int) $programA->id, $ids);
        $this->assertNotContains((int) $programB->id, $ids);
    }

    public function test_faculty_only_sees_programs_tied_to_membership_or_assignment(): void
    {
        Role::findOrCreate(RoleSlug::FACULTY, 'web');

        $college = College::factory()->create();
        $programMine = Program::factory()->create(['college_id' => $college->id]);
        $programOther = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $programMine->id,
            'college_id' => $college->id,
        ]);
        AccreditationArea::factory()->create(['cycle_id' => $cycle->id]);

        $faculty = User::factory()->create([
            'college_id' => $college->id,
            'program_id' => $programMine->id,
        ]);
        $faculty->assignRole(RoleSlug::FACULTY);

        $this->assertTrue(OrgScope::canSeeProgram($faculty, (int) $programMine->id));
        $this->assertFalse(OrgScope::canSeeProgram($faculty, (int) $programOther->id));
    }

    public function test_scoped_cache_invalidation_is_isolated_per_program(): void
    {
        Cache::flush();

        ScopedCache::remember('areas', [
            'role' => 'program-chair',
            'program_id' => 11,
        ], 'list', 60, fn () => ['program' => 11]);

        ScopedCache::remember('areas', [
            'role' => 'program-chair',
            'program_id' => 22,
        ], 'list', 60, fn () => ['program' => 22]);

        ScopedCache::bustProgram(11);

        $still = ScopedCache::remember('areas', [
            'role' => 'program-chair',
            'program_id' => 22,
        ], 'list', 60, fn () => ['program' => 'rebuilt']);

        $this->assertSame(22, $still['program']);

        $rebuilt = ScopedCache::remember('areas', [
            'role' => 'program-chair',
            'program_id' => 11,
        ], 'list', 60, fn () => ['program' => 'fresh-11']);

        $this->assertSame('fresh-11', $rebuilt['program']);
    }

    public function test_sync_cursor_requires_sanctum_and_returns_etag_for_faculty(): void
    {
        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        $user = User::factory()->create();
        $user->assignRole(RoleSlug::FACULTY);

        $this->getJson('/api/sync/cursor')->assertUnauthorized();

        $ok = $this->actingAs($user, 'sanctum')->getJson('/api/sync/cursor');
        $ok->assertOk()->assertHeader('ETag');
    }
}
