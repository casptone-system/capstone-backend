<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('accreditation_cycles', ['level'], 'accreditation_cycles_level_index');
        $this->addIndexIfMissing('accreditation_cycles', ['status'], 'accreditation_cycles_status_index');
        $this->addIndexIfMissing('accreditation_areas', ['status'], 'accreditation_areas_status_index');
        $this->addIndexIfMissing('accreditation_areas', ['code'], 'accreditation_areas_code_index');
        $this->addIndexIfMissing('documents', ['program_id', 'status'], 'documents_program_id_status_index');
        $this->addIndexIfMissing('reviews', ['cycle_id', 'current_status'], 'reviews_cycle_id_current_status_index');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('accreditation_cycles', 'accreditation_cycles_level_index');
        $this->dropIndexIfExists('accreditation_cycles', 'accreditation_cycles_status_index');
        $this->dropIndexIfExists('accreditation_areas', 'accreditation_areas_status_index');
        $this->dropIndexIfExists('accreditation_areas', 'accreditation_areas_code_index');
        $this->dropIndexIfExists('documents', 'documents_program_id_status_index');
        $this->dropIndexIfExists('reviews', 'reviews_cycle_id_current_status_index');
    }

    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        $exists = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index) => ($index['name'] ?? '') === $indexName
                || array_values($index['columns'] ?? []) === $columns
        );

        if ($exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->index($columns, $indexName);
        });
    }

    private function dropIndexIfExists(string $tableName, string $indexName): void
    {
        $exists = collect(Schema::getIndexes($tableName))->contains(
            fn (array $index) => ($index['name'] ?? '') === $indexName
        );

        if (! $exists) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropIndex($indexName);
        });
    }
};
