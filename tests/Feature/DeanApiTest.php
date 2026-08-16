<?php

namespace Tests\Feature;

use App\Models\College;
use App\Models\Document;
use App\Models\Program;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DeanApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_dean_dashboard_uses_direct_college_assignment(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);
        $otherCollege = College::factory()->create(['name' => 'College of Arts']);

        $dean = User::factory()->create([
            'email' => 'dean@example.com',
            'name' => 'Dean User',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        Program::factory()->create(['college_id' => $college->id, 'name' => 'Computer Science', 'code' => 'CS']);
        Program::factory()->create(['college_id' => $otherCollege->id, 'name' => 'History', 'code' => 'HIS']);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/dean/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.college.name', 'College of Engineering')
            ->assertJsonPath('data.programs.0.name', 'Computer Science');
    }

    public function test_dean_programs_endpoint_is_scoped_to_their_college_only(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);
        $otherCollege = College::factory()->create(['name' => 'College of Arts']);

        $dean = User::factory()->create([
            'email' => 'dean2@example.com',
            'name' => 'Dean User 2',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        Program::factory()->create(['college_id' => $college->id, 'name' => 'Computer Science', 'code' => 'CS']);
        Program::factory()->create(['college_id' => $otherCollege->id, 'name' => 'History', 'code' => 'HIS']);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/dean/programs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.college_id', $college->id);
    }

    public function test_dean_documents_endpoint_supports_search_and_filters(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);
        $otherCollege = College::factory()->create(['name' => 'College of Arts']);

        $dean = User::factory()->create([
            'email' => 'dean-docs@example.com',
            'name' => 'Dean Docs',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $program = Program::factory()->create(['college_id' => $college->id, 'name' => 'Information Technology', 'code' => 'IT']);
        $otherProgram = Program::factory()->create(['college_id' => $otherCollege->id, 'name' => 'History', 'code' => 'HIS']);

        Document::factory()->create([
            'program_id' => $program->id,
            'title' => 'Faculty Profile Checklist',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        Document::factory()->create([
            'program_id' => $otherProgram->id,
            'title' => 'Faculty Profile Checklist',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/dean/documents?search=faculty&status=Active&program_id=' . $program->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.title', 'Faculty Profile Checklist');
    }

    public function test_dean_documents_endpoint_supports_requirement_filters(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);

        $dean = User::factory()->create([
            'email' => 'dean-req@example.com',
            'name' => 'Dean Requirement',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $program = Program::factory()->create(['college_id' => $college->id, 'name' => 'Information Technology', 'code' => 'IT']);
        $task = Task::factory()->create(['title' => 'Criterion 1.1 Evidence']);

        Document::factory()->create([
            'program_id' => $program->id,
            'task_id' => $task->id,
            'title' => 'Faculty Profile Checklist',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        Document::factory()->create([
            'program_id' => $program->id,
            'task_id' => null,
            'title' => 'General Program Report',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/dean/documents?task_id=' . $task->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.task_id', $task->id);
    }

    public function test_dean_programs_include_requirement_progress_analytics(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);

        $dean = User::factory()->create([
            'email' => 'dean-analytics@example.com',
            'name' => 'Dean Analytics',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $program = Program::factory()->create(['college_id' => $college->id, 'name' => 'Information Technology', 'code' => 'IT']);
        $task = Task::factory()->create(['title' => 'Criterion 1.1 Evidence', 'status' => 'In Progress']);
        $cycle = $task->area->cycle;
        $cycle->update(['program_id' => $program->id]);

        Document::factory()->create([
            'program_id' => $program->id,
            'area_id' => $task->area_id,
            'task_id' => $task->id,
            'title' => 'Evidence file',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/dean/programs');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.data.0.requirementProgress.totalTasks', 1)
            ->assertJsonPath('data.data.0.requirements.0.title', 'Criterion 1.1 Evidence');
    }

    public function test_document_update_accepts_revision_requested_status(): void
    {
        $college = College::factory()->create(['name' => 'College of Engineering']);
        $dean = User::factory()->create([
            'email' => 'dean-revision@example.com',
            'name' => 'Dean Revision',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $program = Program::factory()->create(['college_id' => $college->id, 'name' => 'Computer Science', 'code' => 'CS']);
        $document = Document::factory()->create([
            'program_id' => $program->id,
            'title' => 'Course Assessment Summary',
            'status' => 'Active',
            'uploaded_by' => $dean->id,
        ]);

        $response = $this->actingAs($dean, 'sanctum')->putJson('/api/documents/' . $document->id, [
            'status' => 'Revision Requested',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'Revision Requested');
    }

    public function test_dean_me_endpoint_returns_college_metadata(): void
    {
        $college = College::factory()->create(['name' => 'College of Information Technology']);

        $dean = User::factory()->create([
            'email' => 'dean-me@example.com',
            'name' => 'Dean Me',
            'college_id' => $college->id,
        ]);
        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        $dean->assignRole('Dean');

        $response = $this->actingAs($dean, 'sanctum')->getJson('/api/me');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.role_slug', 'dean')
            ->assertJsonPath('data.user.college_id', $college->id)
            ->assertJsonPath('data.user.college.name', 'College of Information Technology');
    }
}
