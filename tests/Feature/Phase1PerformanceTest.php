<?php

namespace Tests\Feature;

use App\Jobs\IssueDesignationLetterJob;
use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\Document;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowStatus;
use App\Models\Program;
use App\Models\User;
use App\Services\AreaAssignmentService;
use App\Services\AreaProgressService;
use App\Support\RoleSlug;
use App\Support\ScopedCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase1PerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_scoped_cache_keys_include_scope_and_do_not_collide_across_roles(): void
    {
        Cache::flush();

        $deanKey = ScopedCache::key('dashboard', [
            'role' => 'dean',
            'college_id' => 3,
            'user_id' => 9,
        ], 'summary');
        $chairKey = ScopedCache::key('dashboard', [
            'role' => 'program-chair',
            'program_id' => 3,
            'user_id' => 9,
        ], 'summary');

        $this->assertStringContainsString('role=dean', $deanKey);
        $this->assertStringContainsString('college_id=3', $deanKey);
        $this->assertNotSame($deanKey, $chairKey);

        ScopedCache::remember('dashboard', [
            'role' => 'dean',
            'college_id' => 3,
            'user_id' => 9,
        ], 'summary', 60, fn () => ['secret' => 'dean-only']);

        $other = ScopedCache::remember('dashboard', [
            'role' => 'program-chair',
            'program_id' => 3,
            'user_id' => 9,
        ], 'summary', 60, fn () => ['secret' => 'chair-only']);

        $this->assertSame('chair-only', $other['secret']);
        $this->assertSame('dean-only', Cache::get($deanKey)['secret']);
    }

    public function test_scoped_cache_requires_a_scope_field(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ScopedCache::key('dashboard', [], 'summary');
    }

    public function test_scoped_cache_bust_program_clears_role_entries(): void
    {
        Cache::flush();

        ScopedCache::remember('dashboard', [
            'role' => 'program-chair',
            'program_id' => 20,
        ], 'summary', 60, fn () => ['v' => 1]);

        ScopedCache::bustProgram(20);
        $this->assertNull(Cache::get(ScopedCache::key('dashboard', [
            'role' => 'program-chair',
            'program_id' => 20,
        ], 'summary')));
    }

    public function test_progress_aggregates_match_done_uploaded_approved_rules(): void
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1',
        ]);
        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'A',
            'name' => 'Param',
            'sort_order' => 1,
        ]);
        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Evidence item',
            'sort_order' => 1,
        ]);
        ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'IMPLEMENTATION',
            'sort_order' => 0,
        ]);

        $progress = app(AreaProgressService::class);
        $counts = $progress->countsForArea($area);
        $this->assertSame(1, $counts['total']);
        $this->assertSame(0, $counts['completed']);

        ParameterRowStatus::create([
            'content_row_id' => $row->id,
            'is_done' => true,
            'done_at' => now(),
        ]);
        Document::create([
            'program_id' => $program->id,
            'area_id' => $area->id,
            'content_row_id' => $row->id,
            'title' => 'Link',
            'status' => 'Approved',
            'kind' => 'link',
            'url' => 'https://example.com/evidence',
            'current_version' => 1,
            'uploaded_by' => User::factory()->create()->id,
        ]);

        $percent = $progress->refresh($area->fresh());
        $this->assertSame(100, $percent);
        $this->assertSame(100, (int) $area->fresh()->progress_percent);
        $this->assertSame(100, $progress->storedOrRefresh($area->fresh()));
    }

    public function test_assign_chair_dispatches_letter_job_and_returns_updated_area(): void
    {
        Bus::fake([IssueDesignationLetterJob::class]);
        Notification::fake();

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1',
            'chair_id' => null,
        ]);

        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        Role::findOrCreate(RoleSlug::AREA_IN_CHARGE, 'web');
        Role::findOrCreate(RoleSlug::PROGRAM_CHAIR, 'web');

        $chair = User::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $chair->assignRole(RoleSlug::PROGRAM_CHAIR);
        $program->update(['chair_id' => $chair->id]);

        $faculty = User::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        $faculty->assignRole(RoleSlug::FACULTY);

        AreaMember::create([
            'area_id' => $area->id,
            'user_id' => $faculty->id,
            'role' => 'member',
        ]);

        $result = app(AreaAssignmentService::class)->assignChair($area, $faculty->id, true, $chair);

        $this->assertTrue($result['assigned']);
        $this->assertSame($faculty->id, (int) $result['area']->chair_id);
        $this->assertFalse(
            AreaMember::query()->where('area_id', $area->id)->where('user_id', $faculty->id)->exists()
        );
        Bus::assertDispatched(IssueDesignationLetterJob::class);
    }

    public function test_me_endpoint_omits_permission_enumeration(): void
    {
        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        $user = User::factory()->create();
        $user->assignRole(RoleSlug::FACULTY);

        $response = $this->actingAs($user, 'sanctum')->getJson('/api/me');

        $response->assertOk()
            ->assertJsonPath('data.user.permissions', []);
    }
}
