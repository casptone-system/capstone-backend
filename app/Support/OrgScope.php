<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

final class OrgScope
{
    /**
     * Program IDs the user may see. Null means institution-wide (all programs).
     *
     * @return list<int>|null
     */
    public static function visibleProgramIds(User $user): ?array
    {
        if ($user->isSuperAdmin() || $user->isQA() || $user->isVPAA() || $user->isAccreditor()) {
            return null;
        }

        if ($user->isDean()) {
            $collegeId = $user->college_id;
            if (! $collegeId) {
                return [];
            }

            return Program::query()
                ->where('college_id', $collegeId)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        if ($user->isProgramChair()) {
            $chairedId = $user->chairedProgramId();
            $programId = $user->program_id ? (int) $user->program_id : null;
            $ids = array_values(array_unique(array_filter([$chairedId, $programId])));

            return $ids;
        }

        $fromMembership = $user->program_id ? [(int) $user->program_id] : [];
        $fromAssignedAreas = AccreditationArea::query()
            ->where(function ($query) use ($user) {
                $query->where('chair_id', $user->id)
                    ->orWhereHas('members', fn ($members) => $members->where('user_id', $user->id));
            })
            ->whereHas('cycle')
            ->with('cycle:id,program_id')
            ->get()
            ->pluck('cycle.program_id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($fromMembership, $fromAssignedAreas)));
    }

    public static function canSeeAllPrograms(User $user): bool
    {
        return self::visibleProgramIds($user) === null;
    }

    public static function canSeeProgram(User $user, int $programId): bool
    {
        $ids = self::visibleProgramIds($user);

        if ($ids === null) {
            return Program::query()->whereKey($programId)->exists();
        }

        return in_array($programId, $ids, true);
    }

    public static function constrainPrograms(Builder $query, User $user): Builder
    {
        $column = $query->getModel()->getTable() === 'programs' ? 'id' : 'program_id';

        return self::whereProgramIds($query, self::visibleProgramIds($user), $column);
    }

    public static function constrainCycles(Builder $query, User $user): Builder
    {
        return self::whereProgramIds($query, self::visibleProgramIds($user), 'program_id');
    }

    /**
     * @param  list<int>|null  $ids  Null means no constraint (institution-wide).
     */
    public static function whereProgramIds(Builder $query, ?array $ids, string $column = 'program_id'): Builder
    {
        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('0 = 1');
        }

        return $query->whereIn($column, $ids);
    }

    public static function canViewCycle(User $user, AccreditationCycle $cycle): bool
    {
        if ($user->isVPAA() || $user->isQA() || $user->isSuperAdmin() || $user->isAccreditor()) {
            return true;
        }

        if ($user->isDean()) {
            $collegeId = $user->college_id;

            return (bool) $collegeId && (int) $cycle->program()->value('college_id') === (int) $collegeId;
        }

        if ($user->isProgramChair() && (int) $cycle->program()->value('chair_id') === (int) $user->id) {
            return true;
        }

        return $user->isAreaIncharge() && $cycle->areas()->where('chair_id', $user->id)->exists();
    }

    public static function canManageCycle(User $user, AccreditationCycle $cycle): bool
    {
        $programChairId = $cycle->program()->value('chair_id') ?? $cycle->program?->chair_id;

        return $user->isProgramChair() && (int) $programChairId === (int) $user->id;
    }
}
