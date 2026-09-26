<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ParameterRowCommentResource;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowCommentRead;
use App\Support\ParameterRowCommentNotifier;
use App\Support\RowCommentRoleLabel;
use Illuminate\Http\Request;

class ParameterRowCommentController extends Controller
{
    /**
     * Full chronological thread for a content row.
     */
    public function index(Request $request, ParameterContentRow $parameterContentRow)
    {
        $this->authorize('viewComments', $parameterContentRow);

        $comments = $parameterContentRow->comments()->with('author')->orderBy('id')->get();

        return response()->json([
            'success' => true,
            'message' => 'Comments retrieved successfully.',
            'data' => ParameterRowCommentResource::collection($comments),
        ]);
    }

    /**
     * Post a new comment/reply on a content row.
     */
    public function store(Request $request, ParameterContentRow $parameterContentRow)
    {
        $this->authorize('comment', $parameterContentRow);

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $user = $request->user();
        $parameterContentRow->loadMissing('parameter.area.cycle', 'parameter.area.chair', 'parameter.area.members.user');
        $area = $parameterContentRow->parameter?->area;

        $comment = $parameterContentRow->comments()->create([
            'author_id' => $user->id,
            'author_role' => RowCommentRoleLabel::for($user, $area),
            'body' => $validated['body'],
            'source' => 'comment',
        ]);

        ParameterRowCommentRead::updateOrCreate(
            ['content_row_id' => $parameterContentRow->id, 'user_id' => $user->id],
            ['last_read_at' => now()]
        );

        if ($area) {
            ParameterRowCommentNotifier::notify($parameterContentRow, $comment, $user, $area);
        }

        return response()->json([
            'success' => true,
            'message' => 'Comment posted.',
            'data' => new ParameterRowCommentResource($comment->load('author')),
        ], 201);
    }

    /**
     * Mark the thread as read for the current user (clears their unread badge).
     */
    public function markRead(Request $request, ParameterContentRow $parameterContentRow)
    {
        $this->authorize('viewComments', $parameterContentRow);

        ParameterRowCommentRead::updateOrCreate(
            ['content_row_id' => $parameterContentRow->id, 'user_id' => $request->user()->id],
            ['last_read_at' => now()]
        );

        return response()->json([
            'success' => true,
            'message' => 'Marked as read.',
        ]);
    }
}
