<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->addIndexIfMissing('accreditation_areas', ['cycle_id', 'code', 'id'], 'accreditation_areas_cycle_code_id_index');
        $this->addIndexIfMissing('accreditation_areas', ['chair_id', 'cycle_id'], 'accreditation_areas_chair_cycle_index');
        $this->addIndexIfMissing('accreditation_parameters', ['area_id', 'id'], 'accreditation_parameters_area_id_id_index');
        $this->addIndexIfMissing('parameter_content_rows', ['parameter_id', 'id'], 'parameter_content_rows_parameter_id_id_index');
        $this->addIndexIfMissing('parameter_row_statuses', ['content_row_id', 'is_done'], 'parameter_row_statuses_row_done_index');
        $this->addIndexIfMissing('documents', ['content_row_id', 'status'], 'documents_content_row_status_index');
        $this->addIndexIfMissing('documents', ['area_id', 'created_at'], 'documents_area_id_created_at_index');
        $this->addIndexIfMissing('notifications', ['notifiable_type', 'notifiable_id', 'created_at'], 'notifications_notifiable_created_index');
        $this->addIndexIfMissing('area_members', ['user_id', 'area_id'], 'area_members_user_area_index');
    }

    public function down(): void
    {
        $this->dropIndexIfExists('accreditation_areas', 'accreditation_areas_cycle_code_id_index');
        $this->dropIndexIfExists('accreditation_areas', 'accreditation_areas_chair_cycle_index');
        $this->dropIndexIfExists('accreditation_parameters', 'accreditation_parameters_area_id_id_index');
        $this->dropIndexIfExists('parameter_content_rows', 'parameter_content_rows_parameter_id_id_index');
        $this->dropIndexIfExists('parameter_row_statuses', 'parameter_row_statuses_row_done_index');
        $this->dropIndexIfExists('documents', 'documents_content_row_status_index');
        $this->dropIndexIfExists('documents', 'documents_area_id_created_at_index');
        $this->dropIndexIfExists('notifications', 'notifications_notifiable_created_index');
        $this->dropIndexIfExists('area_members', 'area_members_user_area_index');
    }

    private function addIndexIfMissing(string $tableName, array $columns, string $indexName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

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
        if (! Schema::hasTable($tableName)) {
            return;
        }

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
