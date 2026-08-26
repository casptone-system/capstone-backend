<?php

namespace App\Services;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use App\Models\InstrumentTemplate;
use App\Models\Program;
use App\Support\AreaParameterCatalog;

class AaccupStructureService
{
    /**
     * Level I–III share the same 10 AACCUP areas and parameters.
     * Level IV uses the same area list until a distinct instrument is defined.
     *
     * @return list<string>
     */
    public function sharedLevels(): array
    {
        return ['Level I', 'Level II', 'Level III'];
    }

    public function seedInstrumentTemplates(): void
    {
        foreach ([...$this->sharedLevels(), 'Level IV'] as $level) {
            $template = InstrumentTemplate::query()->updateOrCreate(
                ['level' => $level, 'version' => 1],
                [
                    'name' => "AACCUP {$level} Instrument",
                    'description' => $level === 'Level IV'
                        ? "Default {$level} instrument. Area list matches Levels I–III until a Level IV-specific instrument is published."
                        : "Default {$level} instrument. Levels I–III share the same 10 AACCUP areas and parameters.",
                    'is_active' => true,
                    'status' => 'published',
                ]
            );

            if ($this->templateHasCanonicalAreas($template)) {
                continue;
            }

            $template->areas()->delete();

            foreach (AccreditationArea::AACCUP_AREAS as $index => $areaDef) {
                $area = $template->areas()->create([
                    'name' => $areaDef['name'],
                    'description' => $areaDef['code'],
                    'sort_order' => $index,
                ]);

                foreach (AreaParameterCatalog::parameters()[$areaDef['code']] ?? [] as $parameterIndex => $parameter) {
                    $area->parameters()->create([
                        'code' => $parameter['code'],
                        'name' => $parameter['name'],
                        'sort_order' => $parameterIndex,
                    ]);
                }
            }
        }
    }

    public function seedCycleAreas(AccreditationCycle $cycle): void
    {
        foreach (AccreditationArea::AACCUP_AREAS as $areaDef) {
            $area = AccreditationArea::query()->firstOrCreate(
                [
                    'cycle_id' => $cycle->id,
                    'code' => $areaDef['code'],
                ],
                [
                    'name' => $areaDef['name'],
                    'status' => 'Not Started',
                ]
            );

            AreaParameterCatalog::ensureSeeded($area);
        }
    }

    public function ensureCycle(Program $program, string $level): AccreditationCycle
    {
        $program->loadMissing('college');

        $cycle = AccreditationCycle::query()->firstOrCreate(
            [
                'program_id' => $program->id,
                'level' => $level,
            ],
            [
                'college_id' => $program->college_id,
                'status' => 'Planning',
                'workflow_status' => 'Initial Notice',
                'instrument_name' => "AACCUP {$level} Instrument",
            ]
        );

        $this->seedCycleAreas($cycle);

        return $cycle;
    }

    public function bootstrapProgram(Program $program): void
    {
        $this->seedInstrumentTemplates();

        $program->loadMissing('college');

        $level = in_array($program->accreditation_level, AccreditationCycle::LEVELS, true)
            ? $program->accreditation_level
            : 'Level I';

        $cycle = $this->ensureCycle($program, $level);

        if (! $program->active_cycle_id) {
            $program->update([
                'active_cycle_id' => $cycle->id,
                'accreditation_level' => $level,
            ]);
        }

        $program->refresh();
        $this->ensureOpenLevels($program);
    }

    /**
     * Current program level and every higher AACCUP level stay open.
     * Lower levels are treated as already reached and are not created here.
     */
    public function ensureOpenLevels(Program $program): void
    {
        $current = AccreditationCycle::currentLevelFor($program);

        foreach (AccreditationCycle::LEVELS as $level) {
            if (AccreditationCycle::rank($level) >= AccreditationCycle::rank($current)) {
                $this->ensureCycle($program, $level);
            }
        }
    }

    private function templateHasCanonicalAreas(InstrumentTemplate $template): bool
    {
        $names = $template->areas()->orderBy('sort_order')->pluck('name')->all();
        $expected = array_column(AccreditationArea::AACCUP_AREAS, 'name');

        return $names === $expected;
    }
}
