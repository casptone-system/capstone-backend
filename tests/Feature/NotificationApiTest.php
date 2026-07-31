<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Document;
use App\Models\Program;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Notifications\DeadlineNearNotification;
use App\Notifications\DocumentUploadedNotification;
use App\Notifications\ReviewApprovedNotification;
use App\Notifications\ReviewRejectedNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Notifications\TaskAssignedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Program $program;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Sanctum::actingAs($this->user);

        $this->program = Program::factory()->create();
        $this->cycle = AccreditationCycle::factory()->create(['program_id' => $this->program->id]);
        $this->area = AccreditationArea::factory()->create([
            'cycle_id' => $this->cycle->id,
            'name' => 'Area I: Vision, Mission, Goals',
        ]);
    }

    public function test_index_returns_paginated_notifications(): void
    {
        // Create some notifications for the user
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'type', 'title', 'message', 'data', 'readAt', 'createdAt', 'updatedAt', 'isRead'],
                ],
            ]);
    }

    public function test_index_can_filter_unread_notifications(): void
    {
        // Create a read notification
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        // Create an unread notification
        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        // Mark the first one as read
        $this->user->notifications()->first()->markAsRead();

        $response = $this->getJson('/api/notifications?filter=unread');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_can_filter_read_notifications(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        // Mark the first one as read
        $this->user->notifications()->first()->markAsRead();

        $response = $this->getJson('/api/notifications?filter=read');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_index_can_filter_by_type(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        $response = $this->getJson('/api/notifications?type=task_assigned');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'task_assigned');
    }

    public function test_show_returns_notification_details(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $notificationId = $this->user->notifications()->first()->id;

        $response = $this->getJson('/api/notifications/' . $notificationId);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $notificationId)
            ->assertJsonPath('data.type', 'task_assigned')
            ->assertJsonPath('data.isRead', false);
    }

    public function test_show_returns_404_for_nonexistent_notification(): void
    {
        $response = $this->getJson('/api/notifications/nonexistent-id');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_show_returns_404_for_other_users_notification(): void
    {
        $otherUser = User::factory()->create();
        $otherUser->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $otherUser->id]),
            'Test User'
        ));

        $notificationId = $otherUser->notifications()->first()->id;

        $response = $this->getJson('/api/notifications/' . $notificationId);

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_mark_as_read(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $notificationId = $this->user->notifications()->first()->id;

        $response = $this->postJson('/api/notifications/' . $notificationId . '/mark-read');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.isRead', true);

        $this->assertNotNull($this->user->notifications()->first()->read_at);
    }

    public function test_mark_as_read_returns_404_for_nonexistent(): void
    {
        $response = $this->postJson('/api/notifications/nonexistent-id/mark-read');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_mark_all_as_read(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        $response = $this->postJson('/api/notifications/mark-all-read');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertEquals(0, $this->user->unreadNotifications()->count());
    }

    public function test_unread_count(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $this->user->notify(new DocumentUploadedNotification(
            Document::factory()->create(['program_id' => $this->program->id, 'uploaded_by' => $this->user->id]),
            'Test User'
        ));

        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.unreadCount', 2);
    }

    public function test_unread_count_returns_zero_when_no_notifications(): void
    {
        $response = $this->getJson('/api/notifications/unread-count');

        $response->assertStatus(200)
            ->assertJsonPath('data.unreadCount', 0);
    }

    public function test_destroy_deletes_notification(): void
    {
        $this->user->notify(new TaskAssignedNotification(
            Task::factory()->create(['area_id' => $this->area->id, 'created_by' => $this->user->id]),
            'Test User'
        ));

        $notificationId = $this->user->notifications()->first()->id;

        $response = $this->deleteJson('/api/notifications/' . $notificationId);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('notifications', ['id' => $notificationId]);
    }

    public function test_destroy_returns_404_for_nonexistent(): void
    {
        $response = $this->deleteJson('/api/notifications/nonexistent-id');

        $response->assertStatus(404)
            ->assertJsonPath('success', false);
    }

    public function test_task_assigned_notification_sent_on_assign_members(): void
    {
        Notification::fake();

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
        ]);

        $member = User::factory()->create();

        $response = $this->postJson('/api/tasks/' . $task->id . '/assign-members', [
            'user_ids' => [$member->id],
        ]);

        $response->assertStatus(200);

        Notification::assertSentTo($member, TaskAssignedNotification::class);
    }

    public function test_task_assigned_notification_not_sent_for_existing_assignment(): void
    {
        Notification::fake();

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
        ]);

        $member = User::factory()->create();

        // First assignment
        $task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        // Try to assign again
        $response = $this->postJson('/api/tasks/' . $task->id . '/assign-members', [
            'user_ids' => [$member->id],
        ]);

        $response->assertStatus(200);

        // Should not send notification for existing assignment
        Notification::assertNotSentTo($member, TaskAssignedNotification::class);
    }

    public function test_document_uploaded_notification_sent_on_upload(): void
    {
        Notification::fake();

        $chair = User::factory()->create();
        $this->area->update(['chair_id' => $chair->id]);

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
        ]);

        \Illuminate\Http\UploadedFile::fake();
        $file = \Illuminate\Http\UploadedFile::fake()->create('doc.pdf', 100, 'application/pdf');

        \Illuminate\Support\Facades\Storage::fake('local');

        $response = $this->postJson('/api/documents', [
            'program_id' => $this->program->id,
            'area_id' => $this->area->id,
            'task_id' => $task->id,
            'title' => 'Test Document',
            'file' => $file,
        ]);

        $response->assertStatus(201);

        Notification::assertSentTo($chair, DocumentUploadedNotification::class);
    }

    public function test_review_requested_notification_sent_on_submit(): void
    {
        Notification::fake();

        $chair = User::factory()->create();
        $this->area->update(['chair_id' => $chair->id]);

        $review = Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'submitted_by' => $this->user->id,
            'current_status' => 'Draft',
        ]);

        $response = $this->postJson('/api/reviews/' . $review->id . '/submit');

        $response->assertStatus(200);

        Notification::assertSentTo($chair, ReviewRequestedNotification::class);
    }

    public function test_review_approved_notification_sent_on_approve(): void
    {
        Notification::fake();

        $submitter = User::factory()->create();
        $review = Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'submitted_by' => $submitter->id,
            'current_status' => 'Submitted',
            'submitted_at' => now(),
        ]);

        // Act as a different user (the chair)
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/reviews/' . $review->id . '/approve');

        $response->assertStatus(200);

        Notification::assertSentTo($submitter, ReviewApprovedNotification::class);
    }

    public function test_review_rejected_notification_sent_on_reject(): void
    {
        Notification::fake();

        $submitter = User::factory()->create();
        $review = Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'submitted_by' => $submitter->id,
            'current_status' => 'Area Approved',
        ]);

        $response = $this->postJson('/api/reviews/' . $review->id . '/reject', [
            'comment' => 'Does not meet standards.',
        ]);

        $response->assertStatus(200);

        Notification::assertSentTo($submitter, ReviewRejectedNotification::class);
    }

    public function test_deadline_near_command_sends_notifications(): void
    {
        Notification::fake();

        $member = User::factory()->create();

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'due_date' => now()->addDays(2),
            'status' => 'In Progress',
        ]);

        $task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $this->artisan('notifications:check-deadline-near', ['--days' => 3])
            ->assertSuccessful()
            ->expectsOutput('Deadline check complete. 1 notification(s) sent.');

        Notification::assertSentTo($member, DeadlineNearNotification::class);
    }

    public function test_deadline_near_command_skips_completed_tasks(): void
    {
        Notification::fake();

        $member = User::factory()->create();

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'due_date' => now()->addDays(2),
            'status' => 'Completed',
        ]);

        $task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $this->artisan('notifications:check-deadline-near', ['--days' => 3])
            ->assertSuccessful();

        Notification::assertNotSentTo($member, DeadlineNearNotification::class);
    }

    public function test_deadline_near_command_dry_run(): void
    {
        Notification::fake();

        $member = User::factory()->create();

        $task = Task::factory()->create([
            'area_id' => $this->area->id,
            'created_by' => $this->user->id,
            'due_date' => now()->addDays(2),
            'status' => 'In Progress',
        ]);

        $task->assignments()->create([
            'user_id' => $member->id,
            'assigned_at' => now(),
        ]);

        $this->artisan('notifications:check-deadline-near', ['--days' => 3, '--dry-run' => true])
            ->assertSuccessful();

        Notification::assertNotSentTo($member, DeadlineNearNotification::class);
    }

    public function test_unauthenticated_access_is_rejected(): void
    {
        auth()->forgetGuards();

        $response = $this->getJson('/api/notifications');

        $response->assertStatus(401);
    }
}