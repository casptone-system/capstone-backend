<?php

namespace Database\Seeders;

use App\Models\AccreditationCycle;
use App\Services\AaccupStructureService;
use Illuminate\Database\Seeder;

class AccreditationAreaSeeder extends Seeder
{
    /**
     * @deprecated Use AccreditationArea::AACCUP_AREAS. Kept for older callers.
     */
    public const DEFAULT_AREAS = [
        'Area I: Vision, Mission, Goals',
        'Area II: Faculty',
        'Area III: Curriculum',
        'Area IV: Support to Students',
        'Area V: Research',
        'Area VI: Extension',
        'Area VII: Library',
        'Area VIII: Physical Plant',
        'Area IX: Laboratories',
        'Area X: Administration',
    ];

    public function run(AaccupStructureService $structure): void
    {
        AccreditationCycle::query()->each(fn (AccreditationCycle $cycle) => $structure->seedCycleAreas($cycle));
    }
}
