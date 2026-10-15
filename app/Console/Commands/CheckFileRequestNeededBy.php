<?php

namespace App\Console\Commands;

use App\Services\FileRequestReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckFileRequestNeededBy extends Command
{
    protected $signature = 'notifications:check-file-request-deadlines
                            {--dry-run : Run without actually sending notifications}';

    protected $description = 'Remind recipient chairs about pending file requests that are due';

    public function handle(FileRequestReminderService $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = $reminders->sendDueReminders($dryRun);

        $this->info("File request reminder check complete. {$sent} notification(s) sent.");

        Log::info('File request needed-by reminder check completed', [
            'notifications_sent' => $sent,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
