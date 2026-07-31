<?php

namespace App\Console\Commands;

use App\Models\Task;
use App\Models\User;
use App\Notifications\DeadlineNearNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckDeadlineNear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'notifications:check-deadline-near
                            {--days=3 : Number of days before deadline to send notification}
                            {--dry-run : Run without actually sending notifications}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check for tasks with approaching deadlines and send notifications to assigned users';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $daysThreshold = (int) $this->option('days');
        $dryRun = (bool) $this->option('dry-run');

        $this->info("Checking for tasks with deadlines within {$daysThreshold} day(s)...");

        // Find tasks that:
        // - Have a due date
        // - Are due within the threshold days
        // - Are not yet overdue (due date is in the future or today)
        // - Are not completed
        $deadlineDate = now()->addDays($daysThreshold)->toDateString();
        $todayDate = now()->toDateString();

        $tasks = Task::whereNotNull('due_date')
            ->where('due_date', '<=', $deadlineDate)
            ->where('due_date', '>=', $todayDate)
            ->where('status', '!=', 'Completed')
            ->with(['assignments.user', 'creator'])
            ->get();

        if ($tasks->isEmpty()) {
            $this->info('No tasks with approaching deadlines found.');
            return self::SUCCESS;
        }

        $this->info("Found {$tasks->count()} task(s) with approaching deadlines.");

        $notificationsSent = 0;

        foreach ($tasks as $task) {
            $daysRemaining = (int) now()->diffInDays($task->due_date, false);

            // Ensure days remaining is non-negative
            $daysRemaining = max(0, $daysRemaining);

            $this->line("Task #{$task->id}: {$task->title} - Due: {$task->due_date->format('Y-m-d')} ({$daysRemaining} day(s) remaining)");

            if ($dryRun) {
                $this->line('  [Dry Run] Would notify ' . $task->assignments->count() . ' assigned user(s).');
                continue;
            }

            // Notify all assigned users
            foreach ($task->assignments as $assignment) {
                if ($assignment->user) {
                    // Check if we already sent a deadline notification for this task to this user
                    $existingNotification = $assignment->user->notifications()
                        ->where('type', DeadlineNearNotification::class)
                        ->where('data->task_id', $task->id)
                        ->exists();

                    if (!$existingNotification) {
                        $assignment->user->notify(new DeadlineNearNotification($task, $daysRemaining));
                        $notificationsSent++;
                        $this->line("  → Notified: {$assignment->user->name} ({$assignment->user->email})");
                    } else {
                        $this->line("  → Skipped (already notified): {$assignment->user->name}");
                    }
                }
            }
        }

        $this->info("Deadline check complete. {$notificationsSent} notification(s) sent.");

        Log::info('Deadline near check completed', [
            'tasks_found' => $tasks->count(),
            'notifications_sent' => $notificationsSent,
            'days_threshold' => $daysThreshold,
            'dry_run' => $dryRun,
        ]);

        return self::SUCCESS;
    }
}