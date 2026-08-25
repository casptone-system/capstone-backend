<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\User;
use App\Notifications\AreaDeadlineReminderNotification;
use App\Support\RoleSlug;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AreaDeadlineReminderService
{
    public const TIMEZONE = 'Asia/Manila';

    public function sendDueReminders(bool $dryRun = false): int
    {
        $sent = 0;

        $areas = AccreditationArea::query()
            ->whereNotNull('deadline')
            ->with(['chair', 'members.user'])
            ->get();

        foreach ($areas as $area) {
            $sent += $this->sendForArea($area, true, $dryRun);
        }

        return $sent;
    }

    /**
     * @param  bool  $includeUpcoming  Also notify when 2–6 days remain (used when a deadline is saved).
     */
    public function sendForArea(AccreditationArea $area, bool $includeUpcoming = false, bool $dryRun = false): int
    {
        $area->loadMissing(['chair', 'members.user', 'cycle.program.chairUser', 'cycle.program.college']);

        if (! $area->deadline) {
            return 0;
        }

        $percent = app(AreaProgressService::class)->refresh($area);
        if ($percent >= 100) {
            return 0;
        }

        $today = now(self::TIMEZONE)->startOfDay();
        $deadline = $area->deadline->copy()->timezone(self::TIMEZONE)->startOfDay();
        $daysRemaining = $this->calendarDaysRemaining($today, $deadline);
        $kind = $this->kindForDaysRemaining($daysRemaining, $includeUpcoming);

        if ($kind === null) {
            return 0;
        }

        $sent = 0;
        $deadlineDate = $deadline->toDateString();

        foreach ($this->recipients($area) as $user) {
            if ($this->alreadySent($user, $area, $kind, $deadlineDate)) {
                continue;
            }

            if ($dryRun) {
                $sent++;
                continue;
            }

            $this->notifyUser($user, $area, $kind, $daysRemaining, $deadlineDate);
            $sent++;
        }

        return $sent;
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function recipients(AccreditationArea $area)
    {
        $recipients = collect([$area->chair])
            ->concat($area->members->map->user)
            ->filter();

        $program = $area->cycle?->program;
        if ($program?->chairUser) {
            $recipients->push($program->chairUser);
        }
        if ($program?->college_id) {
            $dean = User::query()
                ->where('college_id', $program->college_id)
                ->whereHas('roles', fn ($q) => $q->where('name', RoleSlug::DEAN))
                ->first();
            if ($dean) {
                $recipients->push($dean);
            }
        }

        return $recipients->filter()->unique('id')->values();
    }

    private function calendarDaysRemaining(CarbonInterface $today, CarbonInterface $deadline): int
    {
        $start = $today->copy()->startOfDay();
        $end = $deadline->copy()->startOfDay();

        if ($end->lt($start)) {
            return -((int) round($end->diffInDays($start)));
        }

        return (int) round($start->diffInDays($end));
    }

    private function kindForDaysRemaining(int $daysRemaining, bool $includeUpcoming): ?string
    {
        if ($daysRemaining < 0) {
            return 'overdue';
        }

        return match ($daysRemaining) {
            0 => 'deadline_day',
            1 => '1_day',
            7 => '7_days',
            default => ($includeUpcoming && $daysRemaining >= 2 && $daysRemaining <= 6)
                ? 'upcoming'
                : null,
        };
    }

    private function alreadySent(User $user, AccreditationArea $area, string $kind, string $deadlineDate): bool
    {
        return $user->notifications()
            ->where('type', AreaDeadlineReminderNotification::class)
            ->where('data->area_id', $area->id)
            ->where('data->reminder_kind', $kind)
            ->where('data->deadline_date', $deadlineDate)
            ->exists();
    }

    private function notifyUser(
        User $user,
        AccreditationArea $area,
        string $kind,
        int $daysRemaining,
        string $deadlineDate,
    ): void {
        $notification = new AreaDeadlineReminderNotification(
            $area,
            $kind,
            max(0, $daysRemaining),
            $kind === 'overdue',
        );

        try {
            $user->notify($notification);
        } catch (Throwable $e) {
            Log::warning('Area deadline reminder notify failed; storing in-app copy', [
                'user_id' => $user->id,
                'area_id' => $area->id,
                'kind' => $kind,
                'deadline_date' => $deadlineDate,
                'error' => $e->getMessage(),
            ]);

            if ($this->alreadySent($user, $area, $kind, $deadlineDate)) {
                return;
            }

            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => AreaDeadlineReminderNotification::class,
                'data' => $notification->toArray($user),
                'read_at' => null,
            ]);
        }
    }
}
