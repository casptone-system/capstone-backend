<?php

namespace App\Services;

use App\Models\FileRequest;
use App\Models\User;
use App\Notifications\FileRequestNeededByNotification;

class FileRequestReminderService
{
    public const TIMEZONE = 'Asia/Manila';

    public function sendDueReminders(bool $dryRun = false): int
    {
        $today = now(self::TIMEZONE)->toDateString();
        $sent = 0;

        $requests = FileRequest::query()
            ->where('status', FileRequest::STATUS_PENDING)
            ->whereNotNull('needed_by')
            ->whereNull('needed_by_reminded_at')
            ->whereDate('needed_by', '<=', $today)
            ->with('recipientProgram.chairUser', 'requesterProgram')
            ->get();

        foreach ($requests as $fileRequest) {
            $chair = $fileRequest->recipientProgram?->chairUser;
            if (! $chair instanceof User) {
                continue;
            }

            if ($dryRun) {
                $sent++;

                continue;
            }

            $chair->notify(new FileRequestNeededByNotification($fileRequest));
            $fileRequest->forceFill(['needed_by_reminded_at' => now()])->save();
            $sent++;
        }

        return $sent;
    }
}
