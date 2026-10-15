<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Program;
use App\Models\Team;
use App\Models\User;
use App\Notifications\TeamCodeInviteNotification;
use App\Support\RoleSlug;
use App\Support\TeamCodes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamJoinTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_codes_use_an_unambiguous_alphabet(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', TeamCodes::generate());
        }
    }

    public function test_join_refuses_dean_qa_vpaa_superadmin_and_accreditor(): void
    {
        [$team] = $this->team();

        foreach ([RoleSlug::DEAN, RoleSlug::QA, RoleSlug::VPAA, RoleSlug::SUPERADMIN, RoleSlug::ACCREDITOR] as $role) {
            $user = $this->userWithRole($role);
            $this->actingAs($user)->postJson('/api/teams/join', [
                'code' => $team->code,
            ])->assertStatus(403);
            $this->assertNull($user->fresh()->program_id);
        }
    }

    public function test_join_blocks_a_user_who_already_belongs_to_another_program(): void
    {
        [$team, $program] = $this->team();
        $other = Program::factory()->create(['college_id' => $program->college_id]);
        $user = $this->userWithRole(RoleSlug::FACULTY, ['program_id' => $other->id]);

        $this->actingAs($user)->postJson('/api/teams/join', [
            'code' => $team->code,
        ])->assertStatus(422);

        $this->assertSame($other->id, (int) $user->fresh()->program_id);
    }

    public function test_join_grants_faculty_and_never_program_chair_or_area_chair(): void
    {
        [$team, $program] = $this->team();
        $user = User::factory()->create(['program_id' => null]);

        $this->actingAs($user)->postJson('/api/teams/join', [
            'code' => $team->code,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertSame($program->id, (int) $fresh->program_id);
        $this->assertTrue($fresh->isFaculty());
        $this->assertFalse($fresh->isProgramChair());
        $this->assertFalse($fresh->isAreaIncharge());
    }

    public function test_area_in_charge_keeps_that_role_and_also_receives_faculty(): void
    {
        [$team] = $this->team();
        $user = $this->userWithRole(RoleSlug::AREA_IN_CHARGE, ['program_id' => null]);

        $this->actingAs($user)->postJson('/api/teams/join', [
            'code' => $team->code,
        ])->assertOk();

        $fresh = $user->fresh();
        $this->assertTrue($fresh->isAreaIncharge());
        $this->assertTrue($fresh->isFaculty());
        $this->assertFalse($fresh->isProgramChair());
    }

    public function test_regenerate_replaces_the_saved_code_and_revoke_expires_it(): void
    {
        [$team, $program, $chair] = $this->team(withChair: true);
        $original = $team->code;

        $this->actingAs($chair)->postJson("/api/teams/{$team->id}/regenerate")
            ->assertOk();

        $team->refresh();
        $this->assertNotSame($original, $team->code);
        $this->assertMatchesRegularExpression('/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{6}$/', $team->code);

        $faculty = $this->userWithRole(RoleSlug::FACULTY, ['program_id' => null]);
        $this->actingAs($faculty)->postJson('/api/teams/join', ['code' => $original])->assertStatus(404);
        $this->actingAs($faculty)->postJson('/api/teams/join', ['code' => $team->code])->assertOk();

        $this->actingAs($chair)->postJson("/api/teams/{$team->id}/revoke")->assertOk();
        $late = $this->userWithRole(RoleSlug::FACULTY, ['program_id' => null, 'email' => 'late-joiner@example.test']);
        $this->actingAs($late)->postJson('/api/teams/join', ['code' => $team->fresh()->code])->assertStatus(422);
        $this->assertNull($late->fresh()->program_id);
        $this->assertSame($program->id, (int) $team->program_id);
    }

    public function test_unverified_user_cannot_join_when_verification_is_enabled(): void
    {
        config(['adams.email_verification_enabled' => true]);
        [$team] = $this->team();
        $user = User::factory()->unverified()->create();
        Role::findOrCreate(RoleSlug::FACULTY, 'web');
        $user->assignRole(RoleSlug::FACULTY);

        $this->actingAs($user)->postJson('/api/teams/join', [
            'code' => $team->code,
        ])->assertStatus(403);
    }

    public function test_dean_emails_the_program_code_for_a_college_program(): void
    {
        Notification::fake();
        config(['adams.team_invites_enabled' => true]);
        [$team, $program] = $this->team();
        $dean = $this->userWithRole(RoleSlug::DEAN, ['college_id' => $program->college_id]);

        $this->actingAs($dean)->postJson("/api/teams/{$team->id}/invite", [
            'email' => 'new.faculty@example.test',
        ])->assertOk();

        Notification::assertSentOnDemand(TeamCodeInviteNotification::class);
    }

    public function test_email_invites_stay_off_when_the_flag_is_disabled(): void
    {
        config(['adams.team_invites_enabled' => false]);
        [$team, , $chair] = $this->team(withChair: true);

        $this->actingAs($chair)->postJson("/api/teams/{$team->id}/invite", [
            'email' => 'new.faculty@example.test',
        ])->assertStatus(409);
    }

    public function test_join_attempts_are_rate_limited(): void
    {
        [$team] = $this->team();
        $user = $this->userWithRole(RoleSlug::FACULTY, ['program_id' => null]);

        for ($i = 0; $i < 8; $i++) {
            $this->actingAs($user)->postJson('/api/teams/join', ['code' => '00000' . $i])->assertStatus(404);
        }

        $this->actingAs($user)->postJson('/api/teams/join', [
            'code' => $team->code,
        ])->assertStatus(429);
    }

    /**
     * @return array{0: Team, 1: Program, 2: User|null}
     */
    private function team(bool $withChair = false): array
    {
        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id, 'code' => 'BSFAS-' . $college->id]);
        $chair = null;
        if ($withChair) {
            $chair = $this->userWithRole(RoleSlug::PROGRAM_CHAIR, [
                'program_id' => $program->id,
                'college_id' => $college->id,
            ]);
            $program->update(['chair_id' => $chair->id, 'chair' => $chair->name]);
        }

        $team = Team::query()->create([
            'name' => $program->name . ' Team',
            'program_id' => $program->id,
            'code' => TeamCodes::unique(),
            'created_by' => $chair?->id,
        ]);

        return [$team, $program, $chair];
    }

    private function userWithRole(string $role, array $attributes = []): User
    {
        Role::findOrCreate($role, 'web');
        $user = User::factory()->create($attributes);
        $user->assignRole($role);

        return $user;
    }
}
