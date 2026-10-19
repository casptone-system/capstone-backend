<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Phase 6 load benchmark entrypoint.
 * The real harness lives in Tests\Feature\Phase6LoadBenchmarkTest
 * so RefreshDatabase keeps factory data off production databases.
 */
class Phase6LoadBenchmarkCommand extends Command
{
    protected $signature = 'loadtest:phase6';

    protected $description = 'Point to the Phase 6 PHPUnit load benchmark (safe RefreshDatabase)';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->error('Refusing to run in production.');
        }

        $this->warn('Run the isolated PHPUnit harness (writes storage/app/phase6-load.json):');
        $this->line('  php artisan test --filter=Phase6LoadBenchmarkTest');

        return self::SUCCESS;
    }
}
