<?php

namespace App\Console\Commands;

use App\Models\AccreditationArea;
use App\Services\AreaProgressService;
use Illuminate\Console\Command;

class RebuildAreaProgressCommand extends Command
{
    protected $signature = 'adams:rebuild-area-progress {--area= : Rebuild a single accreditation area id}';

    protected $description = 'Recompute denormalized area progress_percent values from content-row aggregates';

    public function handle(AreaProgressService $progress): int
    {
        $areaId = $this->option('area');
        $query = AccreditationArea::query()->whereNotNull('code')->orderBy('id');
        if ($areaId) {
            $query->where('id', (int) $areaId);
        }

        $count = 0;
        $query->chunkById(50, function ($areas) use ($progress, &$count) {
            foreach ($areas as $area) {
                $progress->refresh($area);
                $count++;
            }
        });

        $this->info("Rebuilt progress for {$count} area(s).");

        return self::SUCCESS;
    }
}
