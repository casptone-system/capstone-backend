<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\AccreditationParameter;
use App\Models\AreaMember;
use App\Models\College;
use App\Models\ParameterContentRow;
use App\Models\Program;
use App\Models\User;
use App\Notifications\AreaDeadlineReminderNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AreaDeadlineAndDeanProgressTest extends TestCase
{
    public function test_dean_dashboard_includes_per_area_progress_for_college_programs_only(): void
    {
        Storage::fake('local');

        Role::firstOrCreate(['name' => 'Dean', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'access-college-dashboard', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $otherCollege = College::factory()->create();
        $dean = User::factory()->create(['college_id' => $college->id]);
        $dean->assignRole('Dean');
        $dean->givePermissionTo('access-college-dashboard');

        $program = Program::factory()->create(['college_id' => $college->id, 'name' => 'Computer Science']);
        $otherProgram = Program::factory()->create(['college_id' => $otherCollege->id, 'name' => 'History']);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);
        $program->update(['active_cycle_id' => $cycle->id]);
        AccreditationCycle::factory()->create(['program_id' => $otherProgram->id]);

        $chair = User::factory()->create(['program_id' => $program->id]);
        $chair->assignRole('Area In-Charge');

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-1',
            'chair_id' => $chair->id,
            'progress_percent' => 0,
        ]);

        $parameter = AccreditationParameter::create([
            'area_id' => $area->id,
            'code' => 'A',
            'name' => 'Test parameter',
            'sort_order' => 1,
        ]);
        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Need a PDF',
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($chair);
        $this->postJson('/api/documents', [
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'title' => 'Evidence',
            'file' => UploadedFile::fake()->create('evidence.pdf', 40, 'application/pdf'),
        ])->assertStatus(201);

        Sanctum::actingAs($dean);
        $response = $this->getJson('/api/dean/dashboard')->assertStatus(200);

        $programs = $response->json('data.programs');
        $this->assertCount(1, $programs);
        $this->assertSame('Computer Science', $programs[0]['name']);
        $this->assertNotEmpty($programs[0]['areaProgress']);
        $this->assertSame(100, $programs[0]['areaProgress'][0]['progressPercent']);
        $this->assertSame('area-1', $programs[0]['areaProgress'][0]['code']);
    }

    public function test_area_deadline_reminders_skip_complete_areas_and_notify_chair_and_members(): void
    {
        Notification::fake();
        Storage::fake('local');

        Role::firstOrCreate(['name' => 'Faculty', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);

        $chair = User::factory()->create(['program_id' => $program->id]);
        $chair->assignRole('Area In-Charge');
        $member = User::factory()->create(['program_id' => $program->id]);
        $member->assignRole('Faculty');

        $incomplete = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-2',
            'chair_id' => $chair->id,
            'deadline' => now()->addDays(7),
            'progress_percent' => 0,
        ]);
        AreaMember::create(['area_id' => $incomplete->id, 'user_id' => $member->id, 'role' => 'member']);

        $completeChair = User::factory()->create(['program_id' => $program->id]);
        $completeChair->assignRole('Area In-Charge');
        $complete = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-3',
            'chair_id' => $completeChair->id,
            'deadline' => now()->addDays(7),
        ]);
        $parameter = AccreditationParameter::create([
            'area_id' => $complete->id,
            'code' => 'A',
            'name' => 'Done parameter',
            'sort_order' => 1,
        ]);
        $row = ParameterContentRow::create([
            'parameter_id' => $parameter->id,
            'content' => 'Already uploaded',
            'sort_order' => 1,
        ]);

        Sanctum::actingAs($completeChair);
        $this->postJson('/api/documents', [
            'program_id' => $program->id,
            'content_row_id' => $row->id,
            'title' => 'Done',
            'file' => UploadedFile::fake()->create('done.pdf', 20, 'application/pdf'),
        ])->assertStatus(201);

        $this->artisan('notifications:check-area-deadlines')->assertSuccessful();

        Notification::assertSentTo($chair, AreaDeadlineReminderNotification::class);
        Notification::assertSentTo($member, AreaDeadlineReminderNotification::class);
        Notification::assertNotSentTo($completeChair, AreaDeadlineReminderNotification::class);
    }

    public function test_setting_tomorrow_deadline_notifies_area_in_charge_immediately(): void
    {
        Notification::fake();

        Role::firstOrCreate(['name' => 'program-chair', 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => 'area-in-charge', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $programChair = User::factory()->create(['college_id' => $college->id]);
        $programChair->assignRole('program-chair');
        $program = Program::factory()->create([
            'college_id' => $college->id,
            'chair_id' => $programChair->id,
        ]);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);

        $areaChair = User::factory()->create(['program_id' => $program->id]);
        $areaChair->assignRole('area-in-charge');

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-4',
            'chair_id' => $areaChair->id,
            'progress_percent' => 0,
        ]);

        Sanctum::actingAs($programChair);
        $this->postJson("/api/accreditation-areas/{$area->id}/set-deadline", [
            'deadline' => now('Asia/Manila')->addDay()->format('Y-m-d 17:00:00'),
        ])->assertStatus(200);

        Notification::assertSentTo($areaChair, function (AreaDeadlineReminderNotification $notification) {
            return $notification->kind === '1_day';
        });
    }

    public function test_area_deadline_command_uses_manila_calendar_day_for_tomorrow(): void
    {
        Notification::fake();

        Role::firstOrCreate(['name' => 'Area In-Charge', 'guard_name' => 'web']);

        $college = College::factory()->create();
        $program = Program::factory()->create(['college_id' => $college->id]);
        $cycle = AccreditationCycle::factory()->create(['program_id' => $program->id]);
        $chair = User::factory()->create(['program_id' => $program->id]);
        $chair->assignRole('Area In-Charge');

        $area = AccreditationArea::factory()->create([
            'cycle_id' => $cycle->id,
            'code' => 'area-5',
            'chair_id' => $chair->id,
            'deadline' => now('Asia/Manila')->addDay()->startOfDay(),
            'progress_percent' => 0,
        ]);

        $this->artisan('notifications:check-area-deadlines')->assertSuccessful();

        Notification::assertSentTo($chair, function (AreaDeadlineReminderNotification $notification) use ($area) {
            return $notification->kind === '1_day' && $notification->area->is($area);
        });
    }
}
