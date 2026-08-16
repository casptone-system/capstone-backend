<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\College;
use App\Models\Invitation;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProgramInvitationPolicyTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsRole(string $roleName, array $attributes = []): User
    {
        Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        $user = User::factory()->create($attributes);
        $user->assignRole($roleName);
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_dean_can_invite_faculty_to_their_own_program(): void
    {
        $dean = $this->actingAsRole('dean');
        College::factory()->create();
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id])->save();

        $response = $this->postJson('/api/programs/' . $program->id . '/invitations', [
            'email' => 'faculty@example.com',
            'role' => 'faculty',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('invitations', [
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'status' => 'pending',
        ]);
    }

    public function test_dean_cannot_invite_to_another_program(): void
    {
        $dean = $this->actingAsRole('dean');
        $programA = Program::factory()->create();
        $programB = Program::factory()->create();
        $dean->forceFill(['program_id' => $programA->id])->save();

        $response = $this->postJson('/api/programs/' . $programB->id . '/invitations', [
            'email' => 'faculty@example.com',
        ]);

        $response->assertStatus(403);
    }

    public function test_dean_cannot_view_another_program_invitations(): void
    {
        $dean = $this->actingAsRole('dean');
        College::factory()->create();
        College::factory()->create();
        $programA = Program::factory()->create();
        $programB = Program::factory()->create();
        $dean->forceFill(['program_id' => $programA->id])->save();
        Invitation::create([
            'program_id' => $programB->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'INVITATIONVIEWTEST',
            'invited_by' => $dean->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->getJson('/api/programs/' . $programB->id . '/invitations');

        $response->assertStatus(403);
    }

    public function test_faculty_can_accept_valid_invitation(): void
    {
        $dean = $this->actingAsRole('dean', ['email' => 'dean@example.com']);
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id, 'college_id' => $program->college_id])->save();

        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'VALIDTOKEN123456',
            'invited_by' => $dean->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('program_members', ['program_id' => $program->id, 'user_id' => $faculty->id]);
        $this->assertDatabaseMissing('invitations', ['token' => $invitation->token]);
    }

    public function test_faculty_cannot_accept_invitation_for_another_email(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'other@example.com',
            'role' => 'faculty',
            'token' => 'ANOTHERINVITATION',
            'invited_by' => $faculty->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(403);
    }

    public function test_faculty_request_requires_approval_before_membership_is_created(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'REQUESTAPPROVALTOKEN',
            'invited_by' => $faculty->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('program_members', ['program_id' => $program->id, 'user_id' => $faculty->id]);
        $this->assertDatabaseHas('invitations', ['token' => $invitation->token, 'status' => 'requested']);
    }

    public function test_dean_can_approve_pending_faculty_membership_request(): void
    {
        $dean = $this->actingAsRole('dean');
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id, 'college_id' => $program->college_id])->save();

        $faculty = User::factory()->create([
            'email' => 'pendingfaculty@example.com',
            'college_id' => $program->college_id,
            'program_id' => null,
        ]);
        $faculty->assignRole('faculty');

        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => $faculty->email,
            'role' => 'faculty',
            'token' => 'APPROVEINVITATIONTOKEN',
            'invited_by' => $dean->id,
            'expires_at' => now()->addDay(),
            'status' => 'requested',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/approve');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('program_members', ['program_id' => $program->id, 'user_id' => $faculty->id, 'role' => 'faculty']);
        $this->assertDatabaseMissing('invitations', ['token' => $invitation->token]);
    }

    public function test_faculty_can_be_promoted_to_program_chair(): void
    {
        $dean = $this->actingAsRole('dean');
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id, 'college_id' => $program->college_id])->save();

        $faculty = User::factory()->create([
            'email' => 'chaircandidate@example.com',
            'college_id' => $program->college_id,
            'program_id' => null,
        ]);
        $faculty->assignRole('faculty');

        $response = $this->putJson('/api/programs/' . $program->id, ['chair_id' => $faculty->id]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $program->refresh();
        $this->assertEquals($faculty->id, $program->chair_id);
        $this->assertDatabaseHas('program_members', ['program_id' => $program->id, 'user_id' => $faculty->id, 'role' => 'program-chair']);
    }

    public function test_expired_invitation_cannot_be_accepted(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'EXPIREDINVITATION',
            'invited_by' => $faculty->id,
            'expires_at' => now()->subDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(400);
    }

    public function test_revoked_invitation_cannot_be_accepted(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'REVOKEDINVITATION',
            'invited_by' => $faculty->id,
            'expires_at' => now()->addDay(),
            'status' => 'revoked',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(400);
    }

    public function test_duplicate_membership_is_prevented(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();
        ProgramMember::create(['program_id' => $program->id, 'user_id' => $faculty->id, 'role' => 'faculty']);

        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'DUPLICATEINVITATION',
            'invited_by' => $faculty->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/accept');

        $response->assertStatus(200);
        $this->assertEquals(1, ProgramMember::where('program_id', $program->id)->where('user_id', $faculty->id)->count());
    }

    public function test_faculty_cannot_access_unauthorized_program(): void
    {
        $faculty = $this->actingAsRole('faculty', ['email' => 'faculty@example.com']);
        $program = Program::factory()->create();

        $response = $this->getJson('/api/programs/' . $program->id);

        $response->assertStatus(403);
    }

    public function test_program_chair_cannot_access_another_program(): void
    {
        $chair = $this->actingAsRole('program-chair');
        $programA = Program::factory()->create();
        $programB = Program::factory()->create();
        $chair->forceFill(['program_id' => $programA->id])->save();

        $response = $this->getJson('/api/programs/' . $programB->id);

        $response->assertStatus(403);
    }

    public function test_area_incharge_cannot_access_another_program(): void
    {
        $areaInCharge = $this->actingAsRole('area-incharge');
        $programA = Program::factory()->create();
        $cycleA = AccreditationCycle::factory()->create(['program_id' => $programA->id]);
        $areaA = AccreditationArea::factory()->create(['cycle_id' => $cycleA->id, 'chair_id' => $areaInCharge->id]);
        $programB = Program::factory()->create();
        $cycleB = AccreditationCycle::factory()->create(['program_id' => $programB->id]);
        AccreditationArea::factory()->create(['cycle_id' => $cycleB->id]);

        $response = $this->getJson('/api/programs/' . $programB->id);

        $response->assertStatus(403);
    }

    public function test_invitation_resend_requires_authorization(): void
    {
        $faculty = $this->actingAsRole('faculty');
        $program = Program::factory()->create();
        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'RESENDTESTTOKEN',
            'invited_by' => $faculty->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/resend');

        $response->assertStatus(403);
    }

    public function test_invitation_resend_is_rate_limited(): void
    {
        $dean = $this->actingAsRole('dean');
        College::factory()->create();
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id])->save();
        RateLimiter::clear('invitation-resend:' . $dean->id);

        $invitation = Invitation::create([
            'program_id' => $program->id,
            'email' => 'faculty@example.com',
            'role' => 'faculty',
            'token' => 'RESENDRATE12345',
            'invited_by' => $dean->id,
            'expires_at' => now()->addDay(),
            'status' => 'pending',
        ]);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/invitations/' . $invitation->token . '/resend');
        }

        $response = $this->postJson('/api/invitations/' . $invitation->token . '/resend');
        $response->assertStatus(429);
    }

    public function test_invitation_creation_is_rate_limited(): void
    {
        $dean = $this->actingAsRole('dean');
        College::factory()->create();
        $program = Program::factory()->create();
        $dean->forceFill(['program_id' => $program->id])->save();
        RateLimiter::clear('invitation-create:' . $dean->id);

        for ($i = 0; $i < 4; $i++) {
            $this->postJson('/api/programs/' . $program->id . '/invitations', ['email' => 'user' . $i . '@example.com']);
        }

        $response = $this->postJson('/api/programs/' . $program->id . '/invitations', ['email' => 'final@example.com']);

        $response->assertStatus(429);
    }
}
