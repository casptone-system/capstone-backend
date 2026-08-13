<?php

namespace Tests\Feature;

use App\Models\Program;
use App\Models\User;
use App\Models\Document;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProgramChairWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        Storage::fake('public');
    }

    public function test_dean_can_create_program_with_new_chair_and_photo(): void
    {
        $dean = User::factory()->create(['name' => 'Dean', 'email' => 'dean-create@example.com']);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        $photo = UploadedFile::fake()->create('chair.png', 100, 'image/png');

        $response = $this->actingAs($dean, 'sanctum')->postJson('/api/programs', [
            'name' => 'Bachelor of Integration Testing',
            'code' => 'BIT-101',
            'chair_name' => 'Juan Dela Cruz',
            'chair_email' => 'juan.dc@example.com',
            'profile_photo' => $photo,
        ]);

        $response->assertStatus(201)->assertJsonPath('success', true);

        $program = Program::where('code', 'BIT-101')->first();
        $this->assertNotNull($program);

        $chairUser = User::where('email', 'juan.dc@example.com')->first();
        $this->assertNotNull($chairUser);
        $this->assertEquals($program->chair_id, $chairUser->id);

        $this->assertTrue($chairUser->hasRole('Program Chair'));

        // profile photo saved
        $this->assertNotEmpty($chairUser->profile_photo);
        Storage::disk('public')->assertExists($chairUser->profile_photo);

        // Program Chair can view their assigned program
        $showResp = $this->actingAs($chairUser, 'sanctum')->getJson("/api/programs/{$program->id}");
        $showResp->assertStatus(200)->assertJsonPath('success', true);

        // Admin can fetch user resource and see profilePhoto
        $admin = User::factory()->create();
        $admin->assignRole('Super Administrator');
        $userResp = $this->actingAs($admin, 'sanctum')->getJson("/api/admin/users/{$chairUser->id}");
        $userResp->assertStatus(200);
        $this->assertArrayHasKey('profilePhoto', $userResp->json('data'));
        $this->assertEquals($chairUser->email, $userResp->json('data.email'));
    }

    public function test_transaction_rolls_back_if_chair_creation_fails_due_to_duplicate_email(): void
    {
        $dean = User::factory()->create();
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        // existing user with same email will cause unique constraint violation when creating chair
        $existing = User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->actingAs($dean, 'sanctum')->postJson('/api/programs', [
            'name' => 'Rollback Program',
            'code' => 'RB-001',
            'chair_name' => 'Existing User',
            'chair_email' => 'existing@example.com',
        ]);

        // Expect server error due to duplicate email when creating user; the program should not exist
        $this->assertDatabaseMissing('programs', ['code' => 'RB-001']);
    }

    public function test_unauthorized_user_cannot_create_program(): void
    {
        $user = User::factory()->create();
        $college = \App\Models\College::factory()->create();

        $response = $this->actingAs($user, 'sanctum')->postJson('/api/programs', [
            'college_id' => $college->id,
            'name' => 'Forbidden Program',
            'code' => 'FOR-001',
            'chair_name' => 'Nope',
            'chair_email' => 'nope@example.com',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('programs', ['code' => 'FOR-001']);
    }

    public function test_program_chair_cannot_create_program(): void
    {
        $chair = User::factory()->create();
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $chair->assignRole('Program Chair');

        $college = \App\Models\College::factory()->create();

        $response = $this->actingAs($chair, 'sanctum')->postJson('/api/programs', [
            'college_id' => $college->id,
            'name' => 'Chair Create Attempt',
            'code' => 'CHAIR-001',
            'chair_name' => 'Someone',
            'chair_email' => 'someone@example.com',
        ]);

        $response->assertStatus(403);
    }

    public function test_dean_can_replace_chair_and_documents_remain_intact(): void
    {
        $dean = User::factory()->create();
        $dean->assignRole('Dean');

        $college = \App\Models\College::factory()->create();
        $program = Program::factory()->create(['name' => 'Handover Program', 'code' => 'HAND-01', 'college_id' => $college->id]);

        // Give dean an effective program in the same college so they can manage programs
        $deanProgram = Program::factory()->create(['college_id' => $college->id]);
        $dean->program_id = $deanProgram->id;
        $dean->save();

        $oldChair = User::factory()->create();
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        $oldChair->assignRole('Program Chair');
        $oldChair->program_id = $program->id;
        $oldChair->save();

        $program->chair_id = $oldChair->id;
        $program->save();

        // create a document tied to the program
        Document::factory()->create(['program_id' => $program->id, 'title' => 'Important Doc']);

        // new chair info
        $response = $this->actingAs($dean, 'sanctum')->putJson("/api/programs/{$program->id}", [
            'chair_id' => null, // to force using new chair data? We'll create new via name/email
        ]);

        // Now create new chair via direct controller method: simulate Dean creating a new chair for program
        $newChair = User::factory()->create(['email' => 'newchair@example.com']);
        $newChair->assignRole('Program Chair');
        $this->assertDatabaseHas('programs', ['id' => $program->id]);

        // perform handover using controller update flow
        $handResp = $this->actingAs($dean, 'sanctum')->putJson("/api/programs/{$program->id}", [
            'chair_id' => $newChair->id,
        ]);

        $handResp->assertStatus(200)->assertJsonPath('success', true);

        // program should now point to new chair
        $program->refresh();
        $this->assertEquals($newChair->id, $program->chair_id);

        // documents remain
        $this->assertDatabaseHas('documents', ['program_id' => $program->id, 'title' => 'Important Doc']);

        // old user still exists and historical activity (document) still links
        $this->assertDatabaseHas('users', ['id' => $oldChair->id]);
    }
}
