<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AreaMember;
use App\Models\Document;
use App\Models\ParameterRowComment;
use App\Models\Program;
use App\Models\Review;
use App\Models\TaskNotification;
use App\Models\User;
use App\Support\OrgScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lightweight change cursors for poll-based cross-user sync (Phase 4 option D).
 * Payloads are timestamps + unread count only — no entity bodies.
 */
class SyncCursorService
{
    /**
     * @return array{
     *   serverTime: string,
     *   unreadCount: int,
     *   cursors: array<string, string|null>
     * }
     */
    public function forUser(User $user): array
    {
        $programIds = OrgScope::visibleProgramIds($user);

        $cursors = [
            'notifications' => $this->notificationsCursor($user),
            'taskNotifications' => $this->taskNotificationsCursor($user),
            'assignments' => $this->assignmentsCursor($user, $programIds),
            'reviews' => $this->reviewsCursor($programIds),
            'documents' => $this->documentsCursor($programIds),
            'comments' => $this->commentsCursor($programIds),
            'progress' => $this->progressCursor($programIds),
            'activeLevel' => $this->activeLevelCursor($programIds),
        ];

        return [
            'serverTime' => now()->toIso8601String(),
            'unreadCount' => $this->unreadCount($user),
            'cursors' => $cursors,
        ];
    }

    public function etag(array $payload): string
    {
        $fingerprint = json_encode([
            'unreadCount' => $payload['unreadCount'] ?? 0,
            'cursors' => $payload['cursors'] ?? [],
        ], JSON_UNESCAPED_SLASHES);

        return '"'.hash('sha256', (string) $fingerprint).'"';
    }

    private function unreadCount(User $user): int
    {
        $inbox = (int) $user->unreadNotifications()->count();
        $tasks = (int) TaskNotification::getActiveBadgeCount($user);

        return $inbox + $tasks;
    }

    private function notificationsCursor(User $user): ?string
    {
        return $this->maxOfColumns(
            $user->notifications()->getQuery()->reorder(),
            ['updated_at', 'created_at', 'read_at']
        );
    }

    private function taskNotificationsCursor(User $user): ?string
    {
        $query = TaskNotification::query()->where('assigned_to_id', $user->id);

        return $this->maxOfColumns($query, ['updated_at', 'created_at', 'viewed_at']);
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function assignmentsCursor(User $user, ?array $programIds): ?string
    {
        $areas = $this->iso($this->scopedAreas($programIds)->max('updated_at'));

        $membersQuery = AreaMember::query()
            ->whereHas('area', function (Builder $query) use ($programIds) {
                $this->constrainAreaToPrograms($query, $programIds);
            });

        // Faculty / area members: only membership rows they can feel (their areas).
        if (! $user->isSuperAdmin() && ! $user->isQA() && ! $user->isVPAA() && ! $user->isDean() && ! $user->isProgramChair() && ! $user->isAccreditor()) {
            $membersQuery->where(function (Builder $query) use ($user) {
                $query->where('user_id', $user->id)
                    ->orWhereHas('area', fn (Builder $area) => $area->where('chair_id', $user->id));
            });
        }

        $members = $this->iso($membersQuery->max('updated_at'));

        return $this->maxIso($areas, $members);
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function reviewsCursor(?array $programIds): ?string
    {
        $value = Review::query()
            ->whereHas('area', function (Builder $query) use ($programIds) {
                $this->constrainAreaToPrograms($query, $programIds);
            })
            ->max('updated_at');

        return $this->iso($value);
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function documentsCursor(?array $programIds): ?string
    {
        $query = Document::query()->where(function (Builder $query) use ($programIds) {
            $query->whereHas('area', function (Builder $area) use ($programIds) {
                $this->constrainAreaToPrograms($area, $programIds);
            });

            if ($programIds === null) {
                $query->orWhereNotNull('program_id');
            } elseif ($programIds === []) {
                $query->orWhereRaw('0 = 1');
            } else {
                $query->orWhereIn('program_id', $programIds);
            }
        });

        return $this->iso($query->max('updated_at'));
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function commentsCursor(?array $programIds): ?string
    {
        $value = ParameterRowComment::query()
            ->whereHas('contentRow.parameter.area', function (Builder $query) use ($programIds) {
                $this->constrainAreaToPrograms($query, $programIds);
            })
            ->max('updated_at');

        return $this->iso($value);
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function progressCursor(?array $programIds): ?string
    {
        return $this->maxOfColumns($this->scopedAreas($programIds), ['progress_computed_at', 'updated_at']);
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function activeLevelCursor(?array $programIds): ?string
    {
        $query = Program::query();
        OrgScope::whereProgramIds($query, $programIds, 'id');

        return $this->iso($query->max('updated_at'));
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function scopedAreas(?array $programIds): Builder
    {
        $query = AccreditationArea::query();
        $this->constrainAreaToPrograms($query, $programIds);

        return $query;
    }

    /**
     * @param  list<int>|null  $programIds
     */
    private function constrainAreaToPrograms(Builder $query, ?array $programIds): void
    {
        if ($programIds === null) {
            return;
        }

        if ($programIds === []) {
            $query->whereRaw('0 = 1');

            return;
        }

        $query->whereHas('cycle', fn (Builder $cycle) => $cycle->whereIn('program_id', $programIds));
    }

    /**
     * @param  list<string>  $columns
     */
    private function maxOfColumns(Builder $query, array $columns): ?string
    {
        $best = null;
        foreach ($columns as $column) {
            $best = $this->maxIso($best, $this->iso((clone $query)->max($column)));
        }

        return $best;
    }

    private function iso(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value)->toIso8601String();
        } catch (\Throwable) {
            return null;
        }
    }

    private function maxIso(?string $a, ?string $b): ?string
    {
        if ($a === null) {
            return $b;
        }
        if ($b === null) {
            return $a;
        }

        return strcmp($a, $b) >= 0 ? $a : $b;
    }
}
