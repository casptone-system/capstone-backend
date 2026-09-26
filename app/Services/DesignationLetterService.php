<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\DesignationFile;
use App\Models\Program;
use App\Models\User;
use App\Support\SimplePdfWriter;
use Illuminate\Support\Carbon;
use RuntimeException;

class DesignationLetterService
{
    public function __construct(private readonly EvidenceStorage $storage) {}

    /**
     * Write a designation letter for the person who was just assigned and
     * keep the PDF on their account so it can be opened from Designation Files.
     */
    public function issue(
        User $assignee,
        AccreditationArea $area,
        string $role,
        ?User $designatedBy = null,
    ): DesignationFile {
        $role = $role === DesignationFile::ROLE_CHAIR
            ? DesignationFile::ROLE_CHAIR
            : DesignationFile::ROLE_MEMBER;

        $area->loadMissing(['cycle.program.college', 'cycle.program.chairUser']);
        $assignee->loadMissing(['program.college', 'college']);

        $program = $area->cycle?->program;
        $signer = $designatedBy ?? $program?->chairUser;
        $signer?->loadMissing(['program.college', 'college']);

        $roleLabel = $this->designationTitle($assignee, $role);
        $areaLabel = $this->areaLabel($area);
        $programName = $this->plain((string) ($program?->name ?: 'the program'));
        $level = $this->plain((string) ($area->cycle?->level ?: $program?->accreditation_level ?: ''));
        $assigneeName = $this->personName($assignee);
        $assigneePlace = $this->organizationalPlace($assignee, $program);
        $signerName = $signer ? $this->personName($signer) : 'Program Chair';
        $signerPlace = $signer
            ? $this->organizationalPlace($signer, $program)
            : $this->organizationalPlace($assignee, $program);

        $designationDate = now()->startOfDay();
        $validUntil = $this->vpaaValidUntil($area);
        $effective = $this->effectiveRange($designationDate, $validUntil);

        $programPhrase = str_ends_with(strtolower($programName), 'program')
            ? $programName
            : $programName.' Program';
        $levelPhrase = $level !== '' ? ' '.$level : '';

        $writer = new SimplePdfWriter;
        $writer->title($assigneeName);
        $writer->subtitle($assigneePlace);
        $writer->subtitle('Isabela State University');
        $writer->subtitle('Echague, Isabela');
        $writer->paragraph('');
        $writer->paragraph(
            'By Virtue of the Authority Vested in me by the Executive Officer, you are hereby designated as '
            .$roleLabel.' of '.$areaLabel.' for '.$programPhrase.' AACCUP Accreditation'.$levelPhrase
            .' of the College of this University.'
        );
        $writer->paragraph('');
        $writer->paragraph(
            'This Designation shall be held concurrently with your regular appointment effective '.$effective
            .' unless sooner terminated by this Office or by higher authority, subject to pertinent rules, regulations and policies of the College.'
        );
        $writer->paragraph('');
        $writer->paragraph('Very truly yours,');
        $writer->paragraph('');
        $writer->heading($signerName);
        $writer->paragraph('Program Chair, '.$signerPlace);

        $binary = $writer->output();
        $downloadName = $this->safeName(sprintf(
            'Designation-%s-%s.pdf',
            $role === DesignationFile::ROLE_CHAIR ? 'Area-Chair' : 'Member',
            $area->code ?: ('area-'.$area->id)
        ));
        $path = sprintf(
            'designations/%d/%d-%s-%s.pdf',
            $assignee->id,
            $area->id,
            $role,
            now()->format('YmdHis').'-'.substr(uniqid(), -6)
        );

        if (! $this->storage->put($path, $binary)) {
            throw new RuntimeException('The designation letter could not be saved.');
        }

        return DesignationFile::create([
            'user_id' => $assignee->id,
            'area_id' => $area->id,
            'designated_by' => $signer?->id,
            'role' => $role,
            'role_label' => $roleLabel,
            'area_label' => $areaLabel,
            'program_name' => $programName,
            'level' => $level !== '' ? $level : null,
            'place_name' => $assigneePlace,
            'signer_name' => $signerName,
            'designation_date' => $designationDate->toDateString(),
            'valid_until' => $validUntil?->toDateString(),
            'file_path' => $path,
            'original_name' => $downloadName,
        ]);
    }

    /**
     * Chairperson when a program chair is designated into the area lead seat,
     * Area Chair for faculty in that seat, and Member otherwise.
     */
    private function designationTitle(User $assignee, string $role): string
    {
        if ($role !== DesignationFile::ROLE_CHAIR) {
            return 'Member';
        }

        return $assignee->isProgramChair() ? 'Chairperson' : 'Area Chair';
    }

    /**
     * The line under the name is the person's department or college.
     * In this app that is the college, including a program chair who is
     * linked only through the program they chair.
     */
    private function organizationalPlace(User $user, ?Program $contextProgram): string
    {
        $user->loadMissing(['college', 'program.college']);

        $collegeName = $user->college?->name ?: $user->program?->college?->name;

        if (! $collegeName) {
            $chaired = $user->chairedProgram();
            $chaired?->loadMissing('college');
            $collegeName = $chaired?->college?->name;
        }

        if (! $collegeName) {
            $contextProgram?->loadMissing('college');
            $collegeName = $contextProgram?->college?->name;
        }

        if ($collegeName) {
            return $this->plain((string) $collegeName);
        }

        if ($user->program?->name) {
            return $this->plain((string) $user->program->name);
        }

        if ($contextProgram?->name) {
            return $this->plain((string) $contextProgram->name);
        }

        return 'College';
    }

    private function areaLabel(AccreditationArea $area): string
    {
        $number = null;
        if (is_string($area->code) && preg_match('/area-(\d+)/i', $area->code, $matches)) {
            $number = $matches[1];
        }

        $prettyName = trim((string) preg_replace('/^area\s*\d+\s*[\-\x{2013}\x{2014}:]+\s*/iu', '', (string) $area->name));
        $prettyName = $this->plain($prettyName);

        if ($number && $prettyName !== '' && ! preg_match('/^area\s*'.$number.'\b/i', $prettyName)) {
            return "Area {$number} - {$prettyName}";
        }

        return $prettyName !== '' ? $prettyName : ($number ? "Area {$number}" : 'Area');
    }

    private function personName(User $user): string
    {
        $full = trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
            $user->first_name,
            $user->middle_name,
            $user->last_name,
        ]))) ?? '');

        if ($full !== '') {
            return $this->plain($full);
        }

        return $this->plain((string) ($user->name ?: $user->email ?: 'Faculty'));
    }

    /**
     * The end of the designation is the validity date the VPAA saved.
     * That date lives on the area's cycle when it was set there, and
     * otherwise on the program's active cycle, which is the cycle the
     * VPAA schedule screen edits.
     */
    private function vpaaValidUntil(AccreditationArea $area): ?Carbon
    {
        $cycle = $area->cycle;
        $onThisCycle = $cycle
            ? AccreditationCycle::query()->whereKey($cycle->id)->value('valid_until')
            : null;

        if ($onThisCycle) {
            return Carbon::parse($onThisCycle)->startOfDay();
        }

        $programId = $cycle?->program_id ?: $area->cycle()->value('program_id');
        if (! $programId) {
            return null;
        }

        $activeCycleId = Program::query()->whereKey($programId)->value('active_cycle_id');
        if ($activeCycleId) {
            $onActiveCycle = AccreditationCycle::query()->whereKey($activeCycleId)->value('valid_until');
            if ($onActiveCycle) {
                return Carbon::parse($onActiveCycle)->startOfDay();
            }
        }

        $onAnyCycle = AccreditationCycle::query()
            ->where('program_id', $programId)
            ->whereNotNull('valid_until')
            ->orderByDesc('updated_at')
            ->value('valid_until');

        return $onAnyCycle ? Carbon::parse($onAnyCycle)->startOfDay() : null;
    }

    private function effectiveRange(Carbon $designationDate, mixed $validUntil): string
    {
        $start = $designationDate->format('F j, Y');

        if (! $validUntil) {
            return $start;
        }

        $end = $validUntil instanceof Carbon
            ? $validUntil
            : Carbon::parse($validUntil);

        return $start.' to '.$end->format('F j, Y');
    }

    private function plain(string $text): string
    {
        $text = str_replace(["\u{2013}", "\u{2014}", "\u{2012}", "\u{2212}"], '-', $text);

        return trim(preg_replace('/\s+/', ' ', $text) ?? $text);
    }

    private function safeName(string $name): string
    {
        $name = preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?? 'designation.pdf';
        $name = trim((string) $name, '-');

        return $name !== '' ? $name : 'designation.pdf';
    }
}
