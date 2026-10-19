<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class Phase4SyncCursorTest extends TestCase
{
    use RefreshDatabase;

    private function facultyInProgram(): array
    {
        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        Role::findOrCreate(RoleSlug::PROGRAM_CHAIR, 'web');

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'name' => 'Area 1',
        ]);

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

        return compact('college', 'program', 'cycle', 'chair', 'faculty');
    }

    public function test_sync_cursor_requires_authentication(): void
    {
        $this->getJson('/api/sync/cursor')->assertUnauthorized();
    }

    public function test_sync_cursor_returns_scoped_payload_for_authenticated_user(): void
    {
        ['faculty' => $faculty] = $this->facultyInProgram();

        $response = $this->actingAs($faculty, 'sanctum')->getJson('/api/sync/cursor');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'serverTime',
                    'unreadCount',
                    'cursors' => [
                        'notifications',
                        'taskNotifications',
                        'assignments',
                        'reviews',
                        'documents',
                        'comments',
                        'progress',
                        'activeLevel',
                    ],
                ],
            ]);

        $this->assertNotEmpty($response->headers->get('ETag'));
    }

    public function test_sync_cursor_returns_304_when_etag_matches(): void
    {
        ['faculty' => $faculty] = $this->facultyInProgram();

        $first = $this->actingAs($faculty, 'sanctum')->getJson('/api/sync/cursor');
        $first->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag);

        $second = $this->actingAs($faculty, 'sanctum')
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/sync/cursor');

        $second->assertStatus(304);
        $this->assertSame($etag, $second->headers->get('ETag'));
    }

    public function test_outsider_does_not_see_other_program_active_level_cursor_as_own_scope(): void
    {
        ['program' => $programA, 'faculty' => $facultyA] = $this->facultyInProgram();
        ['program' => $programB] = $this->facultyInProgram();

        $before = $this->actingAs($facultyA, 'sanctum')->getJson('/api/sync/cursor');
        $before->assertOk();
        $etag = $before->headers->get('ETag');

        // Touch an unrelated program — faculty A must still 304 (scoped cursors unchanged).
        $programB->forceFill(['updated_at' => now()->addMinute()])->save();

        $after = $this->actingAs($facultyA, 'sanctum')
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/sync/cursor');
        $after->assertStatus(304);

        // Touch faculty A's own program — cursor must change.
        $programA->forceFill(['updated_at' => now()->addMinutes(2)])->save();
        $changed = $this->actingAs($facultyA, 'sanctum')
            ->withHeader('If-None-Match', $etag)
            ->getJson('/api/sync/cursor');
        $changed->assertOk();
        $this->assertNotSame($etag, $changed->headers->get('ETag'));
    }
}
