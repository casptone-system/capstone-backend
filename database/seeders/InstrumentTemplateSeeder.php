<?php

namespace Database\Seeders;

use App\Models\InstrumentTemplate;
use Illuminate\Database\Seeder;

class InstrumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            'Area I: Vision, Mission, Goals and Objectives',
            'Area II: Faculty',
            'Area III: Curriculum and Instruction',
            'Area IV: Support to Students',
            'Area V: Research',
            'Area VI: Extension and Community Involvement',
            'Area VII: Library',
            'Area VIII: Physical Plant and Facilities',
            'Area IX: Laboratories',
            'Area X: Administration',
        ];

        $parameters = [
            ['code' => 'A', 'name' => 'Parameter A'],
            ['code' => 'B', 'name' => 'Parameter B'],
            ['code' => 'C', 'name' => 'Parameter C'],
        ];

        $criteria = [
            ['title' => 'System documentation is available and current', 'evidence_type' => 'system'],
            ['title' => 'Implementation records show consistent practice', 'evidence_type' => 'implementation'],
            ['title' => 'Outcomes and results are documented', 'evidence_type' => 'outcomes'],
        ];

        foreach (['Level I', 'Level II', 'Level III', 'Level IV'] as $level) {
            $template = InstrumentTemplate::updateOrCreate(
                ['level' => $level, 'version' => 1],
                [
                    'name' => "AACCUP {$level} Instrument",
                    'description' => "Default {$level} accreditation folder template. QA or VPAA/DI can edit this anytime.",
                    'is_active' => true,
                    'status' => 'published',
                ]
            );

            if ($template->areas()->exists()) {
                continue;
            }

            foreach ($areas as $index => $areaName) {
                $area = $template->areas()->create([
                    'name' => $areaName,
                    'description' => $areaName,
                    'sort_order' => $index,
                ]);

                foreach ($parameters as $parameterIndex => $parameter) {
                    $createdParameter = $area->parameters()->create([
                        'code' => $parameter['code'],
                        'name' => $parameter['name'],
                        'sort_order' => $parameterIndex,
                    ]);

                    foreach ($criteria as $criterionIndex => $criterion) {
                        $createdParameter->criteria()->create([
                            'title' => $criterion['title'],
                            'description' => $criterion['title'],
                            'evidence_type' => $criterion['evidence_type'],
                            'sort_order' => $criterionIndex,
                        ]);
                    }
                }
            }
        }
    }
}
