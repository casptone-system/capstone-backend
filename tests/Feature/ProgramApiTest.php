<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\User;
use Spatie\Permission\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProgramApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dean_can_create_program_with_chair_id(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean@example.com',
            'password' => 'secret123',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $chair = User::factory()->create([
            'name' => 'Chair User',
            'email' => 'chair@example.com',
            'password' => 'secret123',
        ]);
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $chair->assignRole('Program Chair');

        $college = \App\Models\College::factory()->create();
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        $response = $this->actingAs($dean, 'sanctum')->postJson('/api/programs', [
            'name' => 'Bachelor of Testing',
            'code' => 'TEST-101',
            'chair_id' => $chair->id,
            'accreditation_status' => 'compliant',
            'compliance_score' => 85,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.chairId', $chair->id)
            ->assertJsonPath('data.chair', $chair->name);

        $response->assertJsonPath('data.collegeId', $college->id);

        $this->assertDatabaseHas('programs', [
            'code' => 'TEST-101',
            'chair_id' => $chair->id,
            'chair' => $chair->name,
            'college_id' => $college->id,
        ]);
    }

    public function test_dean_can_create_program_without_college_id_and_uses_their_own_college(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean-no-college@example.com',
            'password' => 'secret123',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        $response = $this->actingAs($dean, 'sanctum')->postJson('/api/programs', [
            'name' => 'Bachelor of Auto Assign',
            'code' => 'AUTO-101',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.collegeId', $college->id)
            ->assertJsonPath('data.code', 'AUTO-101');

        $this->assertDatabaseHas('programs', [
            'code' => 'AUTO-101',
            'college_id' => $college->id,
        ]);
    }

    public function test_program_chair_sees_program_members_that_are_registered_as_faculty_even_without_exact_role_name(): void
    {
        $chair = User::factory()->create(['name' => 'Chair User', 'email' => 'chair-faculty@example.com']);
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $chair->assignRole('Program Chair');

        $college = \App\Models\College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $chair->program_id = $program->id;
        $chair->save();

        $faculty = User::factory()->create([
            'name' => 'Registered Faculty',
            'email' => 'registered-faculty@example.com',
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);

        ProgramMember::create([
            'program_id' => $program->id,
            'user_id' => $faculty->id,
            'role' => 'faculty',
            'joined_at' => now(),
            'invited_by' => $chair->id,
        ]);

        $response = $this->actingAs($chair, 'sanctum')->getJson('/api/program-faculty');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $faculty->id)
            ->assertJsonPath('data.0.name', 'Registered Faculty');
    }

    public function test_program_chair_endpoint_is_accessible_to_deans_and_superadmins(): void
    {
        $chairA = User::factory()->create(['name' => 'Chair A', 'email' => 'chair-a@example.com']);
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $chairA->assignRole('Program Chair');

        $chairB = User::factory()->create(['name' => 'Chair B', 'email' => 'chair-b@example.com']);
        $chairB->assignRole('Program Chair');

        $dean = User::factory()->create(['name' => 'Dean User', 'email' => 'dean@example.com']);
        $dean->assignRole('Dean');

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/program-chairs');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');

        $superAdmin = User::factory()->create(['name' => 'Super Admin', 'email' => 'admin@example.com']);
        Role::firstOrCreate(['name' => 'Super Administrator', 'guard_name' => 'web']);
        $superAdmin->assignRole('Super Administrator');

        $response = $this->actingAs($superAdmin, 'sanctum')->getJson('/api/program-chairs');
        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }

    public function test_dean_can_create_program_with_existing_program_chair_id(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean-existing-chair@example.com',
            'password' => 'secret123',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $chair = User::factory()->create([
            'name' => 'Existing Chair',
            'email' => 'existing-chair@example.com',
            'password' => 'secret123',
        ]);
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $chair->assignRole('Program Chair');

        $college = \App\Models\College::factory()->create();
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        $response = $this->actingAs($dean, 'sanctum')->postJson('/api/programs', [
            'name' => 'Bachelor of Existing Chair',
            'code' => 'EXIST-101',
            'chair_id' => $chair->id,
            'accreditation_status' => 'compliant',
            'compliance_score' => 85,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.chairId', $chair->id)
            ->assertJsonPath('data.chair', $chair->name);

        $this->assertDatabaseHas('programs', [
            'code' => 'EXIST-101',
            'chair_id' => $chair->id,
            'chair' => $chair->name,
            'college_id' => $college->id,
        ]);
    }

    public function test_dean_can_remove_faculty_member_from_program(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean-remove@example.com',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $dean->college_id = $college->id;
        $dean->save();

        $faculty = User::factory()->create([
            'name' => 'Faculty User',
            'email' => 'faculty-remove@example.com',
            'college_id' => $college->id,
            'program_id' => $program->id,
        ]);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        $faculty->assignRole('Faculty');

        ProgramMember::create([
            'program_id' => $program->id,
            'user_id' => $faculty->id,
            'role' => 'faculty',
            'joined_at' => now(),
            'invited_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->deleteJson('/api/programs/' . $program->id . '/members/' . $faculty->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('program_members', [
            'program_id' => $program->id,
            'user_id' => $faculty->id,
        ]);
    }

    public function test_program_show_includes_progress_and_submission_stats(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean-progress@example.com',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id, 'compliance_score' => 78]);
        $dean->college_id = $college->id;
        $dean->save();

        $faculty = User::factory()->create([
            'name' => 'Faculty User',
            'email' => 'faculty-progress@example.com',
            'program_id' => $program->id,
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        $faculty->assignRole('Faculty');

        ProgramMember::create([
            'program_id' => $program->id,
            'user_id' => $faculty->id,
            'role' => 'faculty',
            'joined_at' => now(),
            'invited_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/programs/' . $program->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.facultyCount', 1)
            ->assertJsonPath('data.requirementProgress.completionRate', 0)
            ->assertJsonPath('data.submissionStats.totalDocuments', 0);
    }

    public function test_dean_can_view_users_in_their_college_using_users_endpoint(): void
    {
        $dean = User::factory()->create([
            'name' => 'Dean User',
            'email' => 'dean-users@example.com',
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $dean->college_id = $college->id;
        $dean->save();

        $faculty = User::factory()->create([
            'name' => 'Faculty User',
            'email' => 'faculty-users@example.com',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        $faculty->assignRole('Faculty');

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.users.0.email', 'dean-users@example.com');

        $emails = collect($response->json('data.users'))->pluck('email')->all();
        $this->assertContains('faculty-users@example.com', $emails);
    }

    public function test_program_chair_endpoint_is_forbidden_for_non_privileged_users(): void
    {
        $faculty = User::factory()->create(['name' => 'Faculty User', 'email' => 'faculty@example.com']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        $faculty->assignRole('Faculty');

        $response = $this->actingAs($faculty, 'sanctum')->getJson('/api/program-chairs');
        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
