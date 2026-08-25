<?php

namespace App\Console\Commands;

use App\Services\AreaDeadlineReminderService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckAreaDeadlines extends Command
{
    protected $signature = 'notifications:check-area-deadlines
                            {--dry-run : Run without actually sending notifications}';

    protected $description = 'Send in-app and email reminders for incomplete accreditation area deadlines';

    public function handle(AreaDeadlineReminderService $reminders): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $sent = $reminders->sendDueReminders($dryRun);

        $this->info("Area deadline check complete. {$sent} notification(s) sent.");

        Log::info('Area deadline reminder check completed', [
            'notifications_sent' => $sent,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}
