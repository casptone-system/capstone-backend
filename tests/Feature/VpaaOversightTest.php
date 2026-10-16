<?php

namespace Tests\Feature;

use App\Models\AccreditationCycle;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Program;
use App\Models\User;
use App\Support\RoleSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class VpaaOversightTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_vpaa_can_open_the_institutional_dashboard(): void
    {
        $faculty = $this->userWithRole(RoleSlug::FACULTY);

        $this->actingAs($faculty)
            ->getJson('/api/vpaa/dashboard')
            ->assertForbidden();
    }

    public function test_dashboard_flags_expired_validity_and_a_near_visit_with_thin_evidence(): void
    {
        $vpaa = $this->userWithRole(RoleSlug::VPAA, ['name' => 'VPAA Santos']);
        $college = College::factory()->create(['name' => 'College of Computing']);
        $expiredProgram = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Accountancy',
            'chair_id' => null,
            'compliance_score' => 90,
        ]);
        $expired = AccreditationCycle::factory()->create([
            'program_id' => $expiredProgram->id,
            'college_id' => $college->id,
            'level' => 'Level III',
            'status' => 'Completed',
            'valid_until' => now()->subDay()->toDateString(),
            'scheduled_visit' => now()->addDays(40)->toDateString(),
        ]);
        $expiredProgram->update(['active_cycle_id' => $expired->id]);

        $thinChair = User::factory()->create(['name' => 'Chair Reyes']);
        $thinProgram = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Information Technology',
            'chair_id' => $thinChair->id,
            'compliance_score' => 20,
        ]);
        $thin = AccreditationCycle::factory()->create([
            'program_id' => $thinProgram->id,
            'college_id' => $college->id,
            'level' => 'Level II',
            'status' => 'Preparation',
            'valid_until' => now()->addYear()->toDateString(),
            'scheduled_visit' => now()->addDays(10)->toDateString(),
        ]);
        $thinProgram->update(['active_cycle_id' => $thin->id]);

        $steadyChair = User::factory()->create(['name' => 'Chair Diaz']);
        $steadyProgram = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Civil Engineering',
            'chair_id' => $steadyChair->id,
            'compliance_score' => 90,
        ]);
        $steady = AccreditationCycle::factory()->create([
            'program_id' => $steadyProgram->id,
            'college_id' => $college->id,
            'level' => 'Level I',
            'status' => 'Planning',
            'valid_until' => now()->addDays(400)->toDateString(),
            'scheduled_visit' => now()->addDays(200)->toDateString(),
        ]);
        $steadyProgram->update(['active_cycle_id' => $steady->id]);

        AuditLog::create([
            'user_id' => $vpaa->id,
            'user_email' => $vpaa->email,
            'event' => 'edit',
            'method' => 'POST',
            'path' => 'api/accreditation-cycles/'.$thin->id.'/acknowledge',
            'status' => 'success',
            'ip_address' => '127.0.0.1',
        ]);

        $response = $this->actingAs($vpaa)->getJson('/api/vpaa/dashboard')->assertOk();
        $programs = collect($response->json('data.accreditations'));

        $expiredRow = $programs->firstWhere('program', 'BS Accountancy');
        $this->assertSame('CRITICAL', $expiredRow['risk_level']);
        $this->assertTrue($expiredRow['at_risk']);
        $this->assertStringContainsString('validity has expired', $expiredRow['risk_reasons'][0]);

        $thinRow = $programs->firstWhere('program', 'BS Information Technology');
        $this->assertSame('CRITICAL', $thinRow['risk_level']);
        $this->assertSame(20, $thinRow['evidence_completion']);
        $this->assertStringContainsString('survey visit', $thinRow['risk_reasons'][0]);

        $steadyRow = $programs->firstWhere('program', 'BS Civil Engineering');
        $this->assertSame('CLEAR', $steadyRow['risk_level']);
        $this->assertFalse($steadyRow['at_risk']);

        $this->assertSame(2, $response->json('data.summary.at_risk_programs'));
        $this->assertSame(2, $response->json('data.summary.critical_programs'));
        $this->assertSame(1, $response->json('data.summary.expired_validity'));
        $activity = collect($response->json('data.recent_activity'));
        $this->assertTrue($activity->contains(fn (array $item) => str_contains($item['action'], 'acknowledged')));
        $this->assertSame('BS Information Technology · Level II', $activity->firstWhere('action', 'acknowledged an accreditation notice')['details']);
        $this->assertNotEmpty($response->json('data.colleges'));
        $this->assertNotEmpty($response->json('data.alerts'));
    }

    public function test_vpaa_follow_up_reaches_the_college_dean(): void
    {
        $vpaa = $this->userWithRole(RoleSlug::VPAA, ['name' => 'VPAA Santos']);
        $college = College::factory()->create();
        $dean = $this->userWithRole(RoleSlug::DEAN, [
            'college_id' => $college->id,
            'name' => 'Dean Cruz',
        ]);
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'name' => 'BS Information Technology',
            'compliance_score' => 15,
        ]);
        $cycle = AccreditationCycle::factory()->create([
            'program_id' => $program->id,
            'college_id' => $college->id,
            'level' => 'Level II',
            'status' => 'Preparation',
            'valid_until' => now()->addYear()->toDateString(),
            'scheduled_visit' => now()->addDays(12)->toDateString(),
        ]);

        $this->actingAs($vpaa)
            ->postJson('/api/accreditation-cycles/'.$cycle->id.'/notify-college', [
                'message' => 'Please close Area 2 before the survey visit.',
            ])
            ->assertOk()
            ->assertJsonPath('data.dean_name', 'Dean Cruz');

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $dean->id,
            'notifiable_type' => User::class,
        ]);

        $faculty = $this->userWithRole(RoleSlug::FACULTY);
        $this->actingAs($faculty)
            ->postJson('/api/accreditation-cycles/'.$cycle->id.'/notify-college', [
                'message' => 'Should not send',
            ])
            ->assertForbidden();
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
