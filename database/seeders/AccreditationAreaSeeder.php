<?php

namespace Database\Seeders;

use App\Models\AccreditationArea;
use App\Models\AccreditationCycle;
use Illuminate\Database\Seeder;

class AccreditationAreaSeeder extends Seeder
{
    /**
     * The default accreditation areas.
     */
    public const DEFAULT_AREAS = [
        'Area I: Vision, Mission, Goals',
        'Area II: Faculty',
        'Area III: Curriculum',
        'Area IV: Support to Students',
        'Area V: Research',
        'Area VI: Extension',
        'Area VII: Library',
        'Area VIII: Physical Plant',
        'Area IX: Laboratories',
        'Area X: Administration',
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $cycles = AccreditationCycle::all();

        if ($cycles->isEmpty()) {
            return;
        }

        foreach ($cycles as $cycle) {
            foreach (self::DEFAULT_AREAS as $areaName) {
                AccreditationArea::firstOrCreate(
                    [
                        'cycle_id' => $cycle->id,
                        'name' => $areaName,
                    ],
                    [
                        'description' => null,
                        'chair_id' => null,
                        'status' => 'Not Started',
                    ]
                );
            }
        }
    }
}