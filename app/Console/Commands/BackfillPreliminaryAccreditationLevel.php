<?php

namespace App\Console\Commands;

use App\Models\AccreditationCycle;
use App\Models\Program;
use App\Services\AaccupStructureService;
use Illuminate\Console\Command;

class BackfillPreliminaryAccreditationLevel extends Command
{
    protected $signature = 'accreditation:backfill-preliminary';

    protected $description = 'Ensure every existing program has a Preliminary accreditation cycle (with the standard 10 AACCUP areas) so it is selectable as the active level. Safe to re-run.';

    public function handle(AaccupStructureService $structure): int
    {
        $level = 'Preliminary';
        $programs = Program::query()->get();
        $created = 0;
        $verified = 0;

        $this->info("Backfilling '{$level}' cycles for {$programs->count()} program(s)...");

        foreach ($programs as $program) {
            $existing = AccreditationCycle::query()
                ->where('program_id', $program->id)
                ->where('level', $level)
                ->exists();

            $structure->ensureCycle($program, $level);

            if ($existing) {
                $verified++;
            } else {
                $created++;
            }
        }

        $this->info("Done. Created {$created} new Preliminary cycle(s); verified {$verified} existing one(s).");

        return self::SUCCESS;
    }
}
