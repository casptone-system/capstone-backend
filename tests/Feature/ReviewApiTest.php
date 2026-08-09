<?php

namespace Tests\Feature;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ReviewApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Program $program;
    private AccreditationCycle $cycle;
    private AccreditationArea $area;
    private Review $review;

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
        $this->review = Review::factory()->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'submitted_by' => $this->user->id,
            'current_status' => 'Draft',
        ]);
    }

    public function test_index_returns_paginated_reviews(): void
    {
        Review::factory(3)->create();

        $response = $this->getJson('/api/reviews');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'areaId', 'cycleId', 'currentStatus', 'submittedBy', 'submittedAt', 'completedAt', 'createdAt', 'updatedAt', 'expectedReviewerRole', 'isTerminal'],
                ],
            ]);
    }

    public function test_index_can_filter_by_area_id(): void
    {
        $otherArea = AccreditationArea::factory()->create(['cycle_id' => $this->cycle->id]);
        Review::factory(2)->create(['area_id' => $this->area->id, 'cycle_id' => $this->cycle->id]);
        Review::factory(1)->create(['area_id' => $otherArea->id, 'cycle_id' => $this->cycle->id]);

        $response = $this->getJson('/api/reviews?area_id=' . $this->area->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_cycle_id(): void
    {
        $otherProgram = Program::factory()->create();
        $otherCycle = AccreditationCycle::factory()->create(['program_id' => $otherProgram->id]);
        Review::factory(2)->create(['area_id' => $this->area->id, 'cycle_id' => $this->cycle->id]);
        Review::factory(1)->create(['area_id' => $this->area->id, 'cycle_id' => $otherCycle->id]);

        $response = $this->getJson('/api/reviews?cycle_id=' . $this->cycle->id);

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_index_can_filter_by_status(): void
    {
        Review::factory(2)->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Submitted',
            'submitted_by' => $this->user->id,
        ]);
        Review::factory(1)->create([
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
            'current_status' => 'Ready',
            'submitted_by' => $this->user->id,
        ]);

        $response = $this->getJson('/api/reviews?status=Submitted');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_store_creates_review(): void
    {
        $newArea = AccreditationArea::factory()->create(['cycle_id' => $this->cycle->id]);

        $response = $this->postJson('/api/reviews', [
            'area_id' => $newArea->id,
            'cycle_id' => $this->cycle->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.currentStatus', 'Draft')
            ->assertJsonPath('data.areaId', $newArea->id)
            ->assertJsonPath('data.cycleId', $this->cycle->id);
    }

    public function test_store_validates_required_fields(): void
    {
        $response = $this->postJson('/api/reviews', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['area_id', 'cycle_id']);
    }

    public function test_store_prevents_duplicate_active_review(): void
    {
        $response = $this->postJson('/api/reviews', [
            'area_id' => $this->area->id,
            'cycle_id' => $this->cycle->id,
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_show_returns_review_details(): void
    {
        $response = $this->getJson('/api/reviews/' . $this->review->id);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $this->review->id)
            ->assertJsonPath('data.currentStatus', 'Draft')
            ->assertJsonPath('data.isTerminal', false);
    }

    public function test_submit_transitions_draft_to_submitted(): void
    {
        $response = $this->postJson('/api/reviews/' . $this->review->id . '/submit');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Review submitted successfully.')
            ->assertJsonPath('data.currentStatus', 'Submitted')
            ->assertJsonPath('data.expectedReviewerRole', 'Area Chair');

        $this->assertDatabaseHas('reviews', [
            'id' => $this->review->id,
            'current_status' => 'Submitted',
        ]);

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $this->review->id,
            'action' => 'submit',
            'from_status' => 'Draft',
            'to_status' => 'Submitted',
            'role' => 'Member',
        ]);
    }

    public function test_approve_transitions_submitted_to_area_approved(): void
    {
        $this->review->update(['current_status' => 'Submitted', 'submitted_at' => now()]);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/approve');

        $response->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Area Approved')
            ->assertJsonPath('data.expectedReviewerRole', 'Dean');

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $this->review->id,
            'action' => 'approve',
            'role' => 'Area Chair',
        ]);
    }

    public function test_full_workflow_approval_path(): void
    {
        // Draft → Submitted
        $this->review->update(['current_status' => 'Submitted', 'submitted_at' => now()]);

        // Submitted → Area Approved
        $this->postJson('/api/reviews/' . $this->review->id . '/approve');
        $this->assertEquals('Area Approved', $this->review->fresh()->current_status);

        // Area Approved → Dean Approved
        $this->postJson('/api/reviews/' . $this->review->id . '/approve');
        $this->assertEquals('Dean Approved', $this->review->fresh()->current_status);

        // Dean Approved → QA Approved
        $this->postJson('/api/reviews/' . $this->review->id . '/approve');
        $this->assertEquals('QA Approved', $this->review->fresh()->current_status);

        // QA Approved → VPAA Approved
        $this->postJson('/api/reviews/' . $this->review->id . '/approve');
        $this->assertEquals('VPAA Approved', $this->review->fresh()->current_status);

        // VPAA Approved → Ready
        $response = $this->postJson('/api/reviews/' . $this->review->id . '/approve');
        $response->assertJsonPath('data.currentStatus', 'Ready')
            ->assertJsonPath('data.isTerminal', true);

        $this->assertNotNull($this->review->fresh()->completed_at);
    }

    public function test_request_revision_requires_comment(): void
    {
        $this->review->update(['current_status' => 'Submitted', 'submitted_at' => now()]);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/request-revision', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_request_revision_transitions_back(): void
    {
        $this->review->update(['current_status' => 'Submitted', 'submitted_at' => now()]);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/request-revision', [
            'comment' => 'Please update the documentation.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Revision Requested');

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $this->review->id,
            'action' => 'revision_request',
            'role' => 'Area Chair',
            'comment' => 'Please update the documentation.',
        ]);
    }

    public function test_reject_requires_comment(): void
    {
        $this->review->update(['current_status' => 'Area Approved']);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/reject', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['comment']);
    }

    public function test_reject_transitions_to_rejected(): void
    {
        $this->review->update(['current_status' => 'Area Approved']);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/reject', [
            'comment' => 'This does not meet the standards.',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Rejected')
            ->assertJsonPath('data.isTerminal', true);

        $this->assertNotNull($this->review->fresh()->completed_at);
    }

    public function test_revision_requested_can_be_resubmitted(): void
    {
        $this->review->update(['current_status' => 'Revision Requested']);

        $response = $this->postJson('/api/reviews/' . $this->review->id . '/submit');

        $response->assertStatus(200)
            ->assertJsonPath('data.currentStatus', 'Submitted');

        $this->assertDatabaseHas('review_comments', [
            'review_id' => $this->review->id,
            'action' => 'submit',
            'from_status' => 'Revision Requested',
            'to_status' => 'Submitted',
        ]);
    }

    public function test_invalid_transition_returns_error(): void
    {
        // Trying to approve a Draft review (must submit first)
        $response = $this->postJson('/api/reviews/' . $this->review->id . '/approve');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_comments_returns_review_comments(): void
    {
        $this->review->comments()->create([
            'user_id' => $this->user->id,
            'role' => 'Member',
            'action' => 'submit',
            'from_status' => 'Draft',
            'to_status' => 'Submitted',
            'comment' => null,
        ]);

        $this->review->comments()->create([
            'user_id' => $this->user->id,
            'role' => 'Area Chair',
            'action' => 'approve',
            'from_status' => 'Submitted',
            'to_status' => 'Area Approved',
            'comment' => 'Looks good.',
        ]);

        $response = $this->getJson('/api/reviews/' . $this->review->id . '/comments');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data');
    }


        public function test_unauthenticated_access_is_rejected(): void
        {
            // Remove the authenticated user created in setUp().
            Sanctum::actingAs(null);

            $response = $this->getJson('/api/reviews');

            $response->assertStatus(401);
        }


}