<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\ParameterContentRow;
use App\Models\ParameterRowComment;
use App\Models\User;
use App\Notifications\ParameterRowCommentNotification;

final class ParameterRowCommentNotifier
{
    /**
     * Reviewer comment -> notify the area chair and every assigned member.
     * Area chair/member reply -> notify everyone who has previously
     * commented on this row (which covers the original reviewer(s)),
     * excluding the author.
     */
    public static function notify(
        ParameterContentRow $row,
        ParameterRowComment $comment,
        User $author,
        AccreditationArea $area,
    ): void {
        $area->loadMissing('chair', 'members.user');

        $recipientIds = $author->isAssignedToArea($area)
            ? self::priorCommenterIds($row, $author)
            : self::areaParticipantIds($area, $author);

        if ($recipientIds->isEmpty()) {
            return;
        }

        $recipients = User::whereIn('id', $recipientIds)->get();

        foreach ($recipients as $recipient) {
            $recipient->notify(new ParameterRowCommentNotification($row, $comment, $author, $area));
        }
    }

    private static function priorCommenterIds(ParameterContentRow $row, User $author)
    {
        return ParameterRowComment::query()
            ->where('content_row_id', $row->id)
            ->whereNotNull('author_id')
            ->where('author_id', '!=', $author->id)
            ->pluck('author_id')
            ->unique();
    }

    private static function areaParticipantIds(AccreditationArea $area, User $author)
    {
        $ids = collect();

        if ($area->chair_id) {
            $ids->push($area->chair_id);
        }

        foreach ($area->members as $member) {
            if ($member->user_id) {
                $ids->push($member->user_id);
            }
        }

        return $ids->unique()->reject(fn ($id) => (int) $id === (int) $author->id);
    }
}
