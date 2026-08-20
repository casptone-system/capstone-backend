<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Program;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccreditationMessagingTest extends TestCase
{
    use RefreshDatabase;

    public function test_faculty_cannot_start_a_conversation_with_qa(): void
    {
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'QA', 'guard_name' => 'web']);

        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');
        $qa = User::factory()->create();
        $qa->assignRole('QA');

        $this->actingAs($faculty, 'sanctum')
            ->postJson('/api/messages/conversations', [
                'subject' => 'Hello QA',
                'type' => 'qa_chair',
                'participant_ids' => [$qa->id],
            ])
            ->assertStatus(403);
    }

    public function test_program_chair_can_message_faculty_in_the_same_program(): void
    {
        Role::firstOrCreate(['name' => 'Program Chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $chair = User::factory()->create(['college_id' => $college->id, 'program_id' => $program->id]);
        $chair->assignRole('Program Chair');
        $program->update(['chair_id' => $chair->id]);

        $faculty = User::factory()->create(['college_id' => $college->id, 'program_id' => $program->id]);
        $faculty->assignRole('Faculty');

        $this->actingAs($chair, 'sanctum')
            ->postJson('/api/messages/conversations', [
                'subject' => 'Area II · Parameter A',
                'type' => 'chair_faculty',
                'participant_ids' => [$faculty->id],
            ])
            ->assertCreated()
            ->assertJsonPath('success', true);
    }

    public function test_message_contacts_are_limited_to_the_role_chain(): void
    {
        Role::firstOrCreate(['name' => 'QA', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'VPAA', 'guard_name' => 'web']);

        $qa = User::factory()->create();
        $qa->assignRole('QA');
        $faculty = User::factory()->create();
        $faculty->assignRole('Faculty');
        $vpaa = User::factory()->create();
        $vpaa->assignRole('VPAA');

        $contacts = $this->actingAs($qa, 'sanctum')
            ->getJson('/api/messages/contacts')
            ->assertOk()
            ->json('data.groups');

        $ids = collect($contacts)->flatMap(fn ($group) => collect($group['users'])->pluck('id'))->all();
        $this->assertContains($vpaa->id, $ids);
        $this->assertNotContains($faculty->id, $ids);
    }
}
