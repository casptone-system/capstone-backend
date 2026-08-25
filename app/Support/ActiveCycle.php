<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\Program;
use Illuminate\Support\Collection;

final class ActiveCycle
{
    public static function forProgram(Program $program): ?AccreditationCycle
    {
        $program->loadMissing(['activeCycle', 'accreditationCycles']);

        if ($program->activeCycle) {
            return $program->activeCycle;
        }

        if ($program->accreditation_level) {
            $match = $program->accreditationCycles->firstWhere('level', $program->accreditation_level);
            if ($match) {
                return $match;
            }
        }

        return $program->accreditationCycles->firstWhere('level', 'Level I')
            ?: $program->accreditationCycles->sortByDesc('id')->first();
    }

    /**
     * @param  Collection<int, AccreditationCycle>  $cycles
     * @return Collection<int, AccreditationCycle>
     */
    public static function uniquePerProgram(Collection $cycles): Collection
    {
        return $cycles
            ->groupBy(fn (AccreditationCycle $cycle) => (int) $cycle->program_id)
            ->map(fn (Collection $group) => self::pickFromGroup($group))
            ->filter()
            ->values();
    }

    /**
     * One AACCUP area per program + code, preferring the active cycle copy.
     *
     * @param  Collection<int, AccreditationArea>  $areas
     * @return Collection<int, AccreditationArea>
     */
    public static function uniqueAreasPerProgram(Collection $areas): Collection
    {
        return $areas
            ->sortBy(function (AccreditationArea $area) {
                $program = $area->cycle?->program;
                $activeId = (int) ($program?->active_cycle_id ?? 0);
                $isActive = $activeId > 0 && (int) $area->cycle_id === $activeId;
                $number = preg_match('/area-(\d+)/i', (string) $area->code, $matches) ? (int) $matches[1] : 999;

                return sprintf(
                    '%010d-%03d-%d-%010d',
                    (int) ($area->cycle?->program_id ?? 0),
                    $number,
                    $isActive ? 0 : 1,
                    $area->id
                );
            })
            ->unique(fn (AccreditationArea $area) => ((int) ($area->cycle?->program_id ?? 0)).':'.(string) $area->code)
            ->sortBy(function (AccreditationArea $area) {
                $number = preg_match('/area-(\d+)/i', (string) $area->code, $matches) ? (int) $matches[1] : 999;

                return sprintf('%010d-%03d-%010d', (int) ($area->cycle?->program_id ?? 0), $number, $area->id);
            })
            ->values();
    }

    /**
     * @param  Collection<int, AccreditationCycle>  $group
     */
    private static function pickFromGroup(Collection $group): ?AccreditationCycle
    {
        $program = $group->first()?->program;
        $activeId = (int) ($program?->active_cycle_id ?? 0);

        if ($activeId > 0) {
            $match = $group->firstWhere('id', $activeId);
            if ($match) {
                return $match;
            }
        }

        if ($program?->accreditation_level) {
            $match = $group->firstWhere('level', $program->accreditation_level);
            if ($match) {
                return $match;
            }
        }

        return $group->firstWhere('level', 'Level I')
            ?: $group->sortByDesc('id')->first();
    }
}
