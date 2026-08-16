<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ReviewCommentResource;
use App\Http\Resources\ReviewResource;
use App\Models\AccreditationArea;
use App\Models\Review;
use App\Models\User;
use App\Notifications\ReviewApprovedNotification;
use App\Notifications\ReviewRejectedNotification;
use App\Notifications\ReviewRequestedNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /**
     * Valid workflow transitions.
     */
    private const TRANSITIONS = [
        'Draft' => [
            'submit' => 'Submitted',
        ],
        'Submitted' => [
            'approve' => 'Area Approved',
            'revision_request' => 'Revision Requested',
            'reject' => 'Rejected',
        ],
        'Area Approved' => [
            'approve' => 'Ready',
            'revision_request' => 'Revision Requested',
            'reject' => 'Rejected',
        ],
        'Revision Requested' => [
            'submit' => 'Submitted',
        ],
    ];

    /**
     * Display a paginated list of reviews.
     */
    public function index(Request $request)
    {
        $query = Review::with('area', 'cycle', 'submitter', 'comments.user');

        if ($request->filled('area_id')) {
            $query->where('area_id', $request->area_id);
        }

        if ($request->filled('cycle_id')) {
            $query->where('cycle_id', $request->cycle_id);
        }

        if ($request->filled('status')) {
            $query->where('current_status', $request->status);
        }

        if ($request->filled('submitted_by')) {
            $query->where('submitted_by', $request->submitted_by);
        }

        $reviews = $query->orderBy('created_at', 'desc')->paginate($request->get('per_page', 15));

        return response()->json([
            'success' => true,
            'message' => 'Reviews retrieved successfully.',
            'data' => ReviewResource::collection($reviews),
        ], 200);
    }

    /**
     * Store a newly created review (starts in Draft).
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'area_id' => ['required', 'exists:accreditation_areas,id'],
            'cycle_id' => ['required', 'exists:accreditation_cycles,id'],
        ]);

        $user = $request->user();

        // Only faculty (or superadmin) can create reviews
        if (! ($user->isFaculty() || $user->isSuperAdmin())) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        // Check if a review already exists for this area and cycle
        $existing = Review::where('area_id', $validated['area_id'])
            ->where('cycle_id', $validated['cycle_id'])
            ->whereNotIn('current_status', ['Ready', 'Rejected'])
            ->first();

        if ($existing) {
            return response()->json([
                'success' => false,
                'message' => 'An active review already exists for this area in this cycle.',
                'data' => new ReviewResource($existing->load('area', 'cycle', 'submitter', 'comments.user')),
            ], 409);
        }

        $validated['submitted_by'] = $request->user()->id;
        $validated['current_status'] = 'Draft';

        $review = Review::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Review created successfully.',
            'data' => new ReviewResource($review->load('area', 'cycle', 'submitter', 'comments.user')),
        ], 201);
    }

    /**
     * Display the specified review.
     */
    public function show(Review $review)
    {
        $this->authorize('view', $review);

        $review->load('area.chair', 'cycle.program', 'submitter', 'comments.user');

        return response()->json([
            'success' => true,
            'message' => 'Review retrieved successfully.',
            'data' => new ReviewResource($review),
        ], 200);
    }

    /**
     * Submit the review (Draft → Submitted).
     */
    public function submit(Request $request, Review $review)
    {
        
        return $this->processTransition($request, $review, 'submit', 'Review submitted successfully.');
    }

    /**
     * Approve the review at the current step.
     */
    public function approve(Request $request, Review $review)
    {
        
        return $this->processTransition($request, $review, 'approve', 'Review approved successfully.');
    }

    /**
     * Request revision for the review.
     */
    public function requestRevision(Request $request, Review $review)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string'],
        ]);
        
        return $this->processTransition($request, $review, 'revision_request', 'Revision requested.', $validated['comment']);
    }

    /**
     * Reject the review.
     */
    public function reject(Request $request, Review $review)
    {
        $validated = $request->validate([
            'comment' => ['required', 'string'],
        ]);
        
        return $this->processTransition($request, $review, 'reject', 'Review rejected.', $validated['comment']);
    }

    /**
     * Get the comments for a review.
     */
    public function comments(Review $review)
    {
        $this->authorize('view', $review);

        $comments = $review->comments()->with('user')->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'message' => 'Review comments retrieved successfully.',
            'data' => ReviewCommentResource::collection($comments),
        ], 200);
    }

    /**
     * Process a workflow transition.
     */
    private function processTransition(Request $request, Review $review, string $action, string $successMessage, ?string $comment = null)
    {
        $currentStatus = $review->current_status;

        // Check if the transition is valid
        if (!isset(self::TRANSITIONS[$currentStatus][$action])) {
            return response()->json([
                'success' => false,
                'message' => "Cannot perform '{$action}' on a review with status '{$currentStatus}'.",
            ], 422);
        }

        // Determine the role based on the action and current status
        $role = $this->getRoleForAction($currentStatus, $action);

        $newStatus = $this->resolveNextStatus($review, $action);

        // Authorize the action based on policy AFTER validating transition
        switch ($action) {
            case 'submit':
                $this->authorize('submit', $review);
                break;
            case 'approve':
                $this->authorize('approve', $review);
                break;
            case 'revision_request':
                $this->authorize('requestRevision', $review);
                break;
            case 'reject':
                $this->authorize('reject', $review);
                break;
        }

        // Update timestamps
        $updates = ['current_status' => $newStatus];
        if ($action === 'submit') {
            $updates['submitted_at'] = now();
        }
        if ($newStatus === 'Ready' || $newStatus === 'Rejected') {
            $updates['completed_at'] = now();
        }

        $review->update($updates);

        Log::info('review-transition', [
            'review_id' => $review->id,
            'current_status' => $currentStatus,
            'action' => $action,
            'new_status' => $newStatus,
            'updated_status' => $review->fresh()->current_status,
        ]);

        // Create a comment record for this action
        $review->comments()->create([
            'user_id' => $request->user()->id,
            'role' => $role,
            'action' => $action,
            'from_status' => $currentStatus,
            'to_status' => $newStatus,
            'comment' => $comment,
        ]);

        // Send notifications based on the action
        $this->sendReviewNotifications($request, $review, $action, $role, $comment, $currentStatus);

        $resourcePayload = (new ReviewResource($review->load('area', 'cycle', 'submitter', 'comments.user')))->resolve();
        Log::info('transition-response', ['payload' => $resourcePayload]);

        // If the review is now Revision Requested, reset back to Submitted logic
        // (already handled via the transition table)

        return response()->json([
            'success' => true,
            'message' => $successMessage,
            'data' => $resourcePayload,
        ], 200);
    }

    /**
     * Resolve the next review status for the specified action.
     */
    private function resolveNextStatus(Review $review, string $action): string
    {
        $currentStatus = $review->current_status;

        if ($action === 'approve') {
            return match ($currentStatus) {
                'Submitted' => 'Area Approved',
                'Area Approved' => 'Ready',
                default => $currentStatus,
            };
        }

        if ($action === 'revision_request') {
            return 'Revision Requested';
        }

        if ($action === 'reject') {
            return 'Rejected';
        }

        return self::TRANSITIONS[$currentStatus][$action] ?? $currentStatus;
    }

    /**
     * Get the role for a given action and current status.
     */
    private function getRoleForAction(string $currentStatus, string $action): string
    {
        if ($action === 'submit') {
            return 'Member';
        }

        if ($action === 'revision_request' || $action === 'reject') {
            return match ($currentStatus) {
                'Submitted' => 'Area Chair',
                'Area Approved' => 'Dean',
                default => 'Member',
            };
        }

        return match ($currentStatus) {
            'Submitted' => 'Area Chair',
            'Area Approved' => 'Dean',
            default => 'Member',
        };
    }

    /**
     * Send notifications based on the review workflow action.
     */
    private function sendReviewNotifications(
        Request $request,
        Review $review,
        string $action,
        string $role,
        ?string $comment,
        string $previousStatus,
    ): void {
        $review->load('area.chair', 'submitter');
        $actor = $request->user();
        $actorName = $actor->name;

        switch ($action) {
            case 'submit':
                // Notify the area chair that a review has been submitted for their review
                $review->refresh(); // Refresh to get the new expected reviewer role
                $expectedRole = $review->getExpectedReviewerRole();

                if ($review->area && $review->area->chair_id && $review->area->chair_id !== $actor->id) {
                    $chair = User::find($review->area->chair_id);
                    if ($chair) {
                        $chair->notify(new ReviewRequestedNotification(
                            $review,
                            $actorName,
                            $expectedRole ?? 'Area Chair'
                        ));
                    }
                }
                break;

            case 'approve':
                // Notify the submitter that their review has been approved
                if ($review->submitted_by && $review->submitted_by !== $actor->id) {
                    $submitter = User::find($review->submitted_by);
                    if ($submitter) {
                        $submitter->notify(new ReviewApprovedNotification(
                            $review,
                            $actorName,
                            $role
                        ));
                    }
                }
                break;

            case 'reject':
                // Notify the submitter that their review has been rejected
                if ($review->submitted_by && $review->submitted_by !== $actor->id) {
                    $submitter = User::find($review->submitted_by);
                    if ($submitter) {
                        $submitter->notify(new ReviewRejectedNotification(
                            $review,
                            $actorName,
                            $role,
                            $comment
                        ));
                    }
                }
                break;

            case 'revision_request':
                // Notify the submitter that a revision has been requested
                // This is similar to a review request - the submitter needs to revise and resubmit
                if ($review->submitted_by && $review->submitted_by !== $actor->id) {
                    $submitter = User::find($review->submitted_by);
                    if ($submitter) {
                        $submitter->notify(new ReviewRequestedNotification(
                            $review,
                            $actorName,
                            'Member'
                        ));
                    }
                }
                break;
        }
    }
}
