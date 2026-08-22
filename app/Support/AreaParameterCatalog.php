<?php

namespace App\Support;

use App\Models\AccreditationArea;
use App\Models\AccreditationParameter;
use App\Models\ParameterContentRow;

class AreaParameterCatalog
{
    /**
     * Fixed AACCUP parameter titles keyed by area code.
     *
     * @return array<string, list<array{code: string, name: string}>>
     */
    public static function parameters(): array
    {
        return [
            'area-1' => [
                ['code' => 'A', 'name' => 'STATEMENT OF VISION, MISSION, GOALS AND OBJECTIVES'],
                ['code' => 'B', 'name' => 'DISSEMINATION AND ACCEPTABILITY'],
            ],
            'area-2' => [
                ['code' => 'A', 'name' => 'ACADEMIC QUALIFICATIONS AND PROFESSIONAL EXPERIENCE'],
                ['code' => 'B', 'name' => 'RECRUITMENT, SELECTION AND PROMOTION'],
                ['code' => 'C', 'name' => 'PROFESSIONAL PERFORMANCE AND DEVELOPMENT'],
            ],
            'area-3' => [
                ['code' => 'A', 'name' => 'CURRICULUM AND PROGRAM OF STUDIES'],
                ['code' => 'B', 'name' => 'INSTRUCTIONAL PROCESS AND METHODOLOGIES'],
                ['code' => 'C', 'name' => 'ASSESSMENT OF ACADEMIC PERFORMANCE'],
            ],
            'area-4' => [
                ['code' => 'A', 'name' => 'STUDENT SERVICES PROGRAM'],
                ['code' => 'B', 'name' => 'GUIDANCE, COUNSELING AND CAREER SERVICES'],
                ['code' => 'C', 'name' => 'SCHOLARSHIPS, GRANTS AND STUDENT AID'],
            ],
            'area-5' => [
                ['code' => 'A', 'name' => 'PRIORITIES AND RELEVANCE'],
                ['code' => 'B', 'name' => 'FUNDING AND OTHER RESOURCES'],
                ['code' => 'C', 'name' => 'IMPLEMENTATION, UTILIZATION AND DISSEMINATION'],
            ],
            'area-6' => [
                ['code' => 'A', 'name' => 'PRIORITIES AND RELEVANCE'],
                ['code' => 'B', 'name' => 'PLANNING, IMPLEMENTATION AND EVALUATION'],
                ['code' => 'C', 'name' => 'COMMUNITY INVOLVEMENT AND PARTICIPATION'],
            ],
            'area-7' => [
                ['code' => 'A', 'name' => 'ADMINISTRATION'],
                ['code' => 'B', 'name' => 'COLLECTIONS, HOLDINGS AND SERVICES'],
                ['code' => 'C', 'name' => 'PHYSICAL FACILITIES AND FINANCIAL SUPPORT'],
            ],
            'area-8' => [
                ['code' => 'A', 'name' => 'CAMPUS, BUILDINGS AND GROUNDS'],
                ['code' => 'B', 'name' => 'CLASSROOMS, OFFICES AND SUPPORT SPACES'],
                ['code' => 'C', 'name' => 'SAFETY, MAINTENANCE AND UTILITIES'],
            ],
            'area-9' => [
                ['code' => 'A', 'name' => 'LABORATORIES AND SHOPS'],
                ['code' => 'B', 'name' => 'EQUIPMENT, SUPPLIES AND UTILIZATION'],
                ['code' => 'C', 'name' => 'SAFETY AND MAINTENANCE'],
            ],
            'area-10' => [
                ['code' => 'A', 'name' => 'ORGANIZATION AND ADMINISTRATION'],
                ['code' => 'B', 'name' => 'PLANNING, FINANCIAL MANAGEMENT AND RECORDS'],
                ['code' => 'C', 'name' => 'SUPPORT SERVICES AND EXTERNAL RELATIONS'],
            ],
        ];
    }

    /**
     * First-column content rows keyed by area code then parameter code.
     * Only Area 1 is prefilled; other areas start empty for QA/DI to edit.
     *
     * @return list<string>
     */
    public static function rowsFor(string $areaCode, string $parameterCode): array
    {
        $catalog = [
            'area-1' => [
                'A' => [
                    'SYSTEM - INPUTS AND PROCESSES',
                    'S.1. The institution has a system of determining its Vision and Mission.',
                    'S.2. The Vision clearly reflects what the Institution hopes to become in the future.',
                    'S.3. The Mission clearly reflects the Institution\'s legal and other statutory mandate.',
                    'S.4. The Goals of the College/Academic Unit are consistent with the Mission of the Institution.',
                    'S.5. The Objectives of the program have the expected outcomes in terms of competencies (skills and knowledge), values and other attributes of the graduates which include the development of:',
                    'S.5.1. technical skills in Graduate Education;',
                    'S.5.2. research and extension capabilities;',
                    'S.5.3. students\' own ideas, desirable attitudes and personal discipline;',
                    'S.5.4. moral character;',
                    'S.5.5. critical, analytical, problem solving and other higher order thinking skills; and',
                    'S.5.6. aesthetic and cultural values.',
                    'IMPLEMENTATION',
                    '1.1. The Institution/Academic Unit conducts a review on the statement of the Vision and Mission as well as its goals and program objectives for the approval of authorities concerned.',
                    '1.2. The College/Academic Unit follows a system of formulating its goals and the objectives of the program.',
                    '1.3. The College\'s/Academic Unit\'s faculty, personnel, students and other stakeholders (cooperating agencies, linkages, alumni, industry sector and other concerned groups) participate in the formulation, review and/or revision of the VMGO.',
                    'OUTCOME/S',
                    '0.1. The VMGO are crafted and duly approved by the BOR/BOT.',
                ],
                'B' => [
                    'SYSTEM-INPUTS AND PROCESSES',
                    'S.1. The VMGO are available on bulletin boards, in catalogs/manuals and in other forms of communication media.',
                    'IMPLEMENTATION',
                    '1.1. A system of dissemination and acceptability of the VMGO is enforced.',
                    '1.2. The administrators/faculty attend in-service seminars and training on awareness and acceptability of the:',
                    '1.2.1. Vision and Mission of the Institution;',
                    '1.2.2. Goals of the College/Academic Unit; and',
                    '1.2.3. Objectives of the Program.',
                    '1.3. The formulation/review/revision of the VMGO is participated in by the following:',
                    '1.3.1. administrators;',
                    '1.3.2. faculty;',
                    '1.3.3. staff;',
                    '1.3.4. students; and',
                    '1.3.5. other stakeholders.',
                    '1.4. The faculty and staff perform their jobs/functions in consonance with the VMGO.',
                    '1.5. The VMGO are widely disseminated to the different agencies, institutions, industry sector and the community.',
                    'OUTCOME/S',
                    '0.1. There is full awareness and acceptance of the VMGO by the administrators, faculty, staff, students and other stakeholders.',
                    '0.2. There is congruency between actual educational practices and activities with the following:',
                    '0.2.1. Vision and Mission of the SUC;',
                    '0.2.2. Goals of the College/Academic Unit; and',
                    '0.2.3. Objectives of the Graduate Education program.',
                    '0.3. The goals and objectives are being achieved.',
                ],
            ],
        ];

        return $catalog[$areaCode][$parameterCode] ?? [];
    }

    public static function ensureSeeded(AccreditationArea $area): void
    {
        $areaCode = (string) $area->code;
        if ($areaCode === '') {
            return;
        }

        $definitions = self::parameters()[$areaCode] ?? [];

        foreach ($definitions as $index => $definition) {
            $parameter = AccreditationParameter::firstOrCreate(
                [
                    'area_id' => $area->id,
                    'code' => $definition['code'],
                ],
                [
                    'name' => $definition['name'],
                    'sort_order' => $index,
                ]
            );

            if ($parameter->contentRows()->exists()) {
                continue;
            }

            self::createRows($parameter, $areaCode, $definition['code']);
        }
    }

    /**
     * Replace catalog rows for an area (used when the official instrument text changes).
     * Only parameters that have catalog text are rewritten; empty catalogs are left alone.
     */
    public static function resyncCatalogRows(AccreditationArea $area): void
    {
        $areaCode = (string) $area->code;
        if ($areaCode === '') {
            return;
        }

        self::ensureSeeded($area);

        foreach (self::parameters()[$areaCode] ?? [] as $definition) {
            $rows = self::rowsFor($areaCode, $definition['code']);
            if ($rows === []) {
                continue;
            }

            $parameter = AccreditationParameter::query()
                ->where('area_id', $area->id)
                ->where('code', $definition['code'])
                ->first();

            if (! $parameter) {
                continue;
            }

            $parameter->contentRows()->delete();
            self::createRows($parameter, $areaCode, $definition['code']);
        }
    }

    private static function createRows(AccreditationParameter $parameter, string $areaCode, string $parameterCode): void
    {
        foreach (self::rowsFor($areaCode, $parameterCode) as $rowIndex => $content) {
            ParameterContentRow::create([
                'parameter_id' => $parameter->id,
                'content' => $content,
                'sort_order' => $rowIndex,
            ]);
        }
    }
}
