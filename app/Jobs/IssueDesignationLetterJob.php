<?php

namespace App\Jobs;

use App\Models\AccreditationArea;
use App\Models\DesignationFile;
use App\Models\User;
use App\Services\DesignationLetterService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class IssueDesignationLetterJob implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 90;

    public function __construct(
        public int $assigneeId,
        public int $areaId,
        public string $role,
        public ?int $designatedById = null,
    ) {
        $this->onQueue('mail');
    }

    public function handle(DesignationLetterService $letters): void
    {
        $assignee = User::query()->find($this->assigneeId);
        $area = AccreditationArea::query()->find($this->areaId);
        if (! $assignee || ! $area) {
            return;
        }

        $designatedBy = $this->designatedById
            ? User::query()->find($this->designatedById)
            : null;

        $role = $this->role === DesignationFile::ROLE_CHAIR
            ? DesignationFile::ROLE_CHAIR
            : DesignationFile::ROLE_MEMBER;

        $letters->issue(
            $assignee,
            $area->loadMissing(['cycle.program.college', 'cycle.program.chairUser']),
            $role,
            $designatedBy,
        );
    }

    public function failed(?Throwable $exception): void
    {
        report($exception);
    }
}
