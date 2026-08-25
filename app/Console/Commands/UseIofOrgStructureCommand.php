<?php

namespace App\Console\Commands;

use Database\Seeders\OrgStructureSeeder;
use Illuminate\Console\Command;

class UseIofOrgStructureCommand extends Command
{
    protected $signature = 'org:use-iof
                            {--prune : Remove colleges and programs other than IOF / BSFAS}';

    protected $description = 'Ensure Institute of Fisheries (IOF) and BSFAS exist as the current respondent unit';

    public function handle(OrgStructureSeeder $seeder): int
    {
        $program = $seeder->seedIof();
        $college = $program->college;

        $this->info(sprintf(
            '%s (%s) on %s now has %s (%s).',
            $college?->name,
            $college?->code,
            $college?->campus ?: config('institution.campus'),
            $program->name,
            $program->code
        ));

        if ($this->option('prune')) {
            if ($this->laravel->environment('production')) {
                $this->error('Refusing to prune other colleges in production.');

                return self::FAILURE;
            }

            $removed = $seeder->pruneOtherUnits();
            $this->info("Removed {$removed} other college(s). IOF remains the only department.");
        }

        return self::SUCCESS;
    }
}
