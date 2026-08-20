<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            if (! Schema::hasColumn('conversations', 'area_id')) {
                $table->foreignId('area_id')->nullable()->after('accreditation_cycle_id')
                    ->constrained('accreditation_areas')->nullOnDelete();
            }
            if (! Schema::hasColumn('conversations', 'parameter_id')) {
                $table->foreignId('parameter_id')->nullable()->after('area_id')
                    ->constrained('accreditation_parameters')->nullOnDelete();
            }
            if (! Schema::hasColumn('conversations', 'workspace_id')) {
                $table->foreignId('workspace_id')->nullable()->after('parameter_id')
                    ->constrained('accreditation_workspaces')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            foreach (['workspace_id', 'parameter_id', 'area_id'] as $column) {
                if (Schema::hasColumn('conversations', $column)) {
                    $table->dropConstrainedForeignId($column);
                }
            }
        });
    }
};
