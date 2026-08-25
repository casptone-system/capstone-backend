<?php

namespace Database\Seeders;

use App\Services\AaccupStructureService;
use Illuminate\Database\Seeder;

class InstrumentTemplateSeeder extends Seeder
{
    public function run(AaccupStructureService $structure): void
    {
        $structure->seedInstrumentTemplates();
    }
}
