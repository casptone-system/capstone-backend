<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('instrument_templates', function (Blueprint $table) {
            if (Schema::hasColumn('instrument_templates', 'level')) {
                try {
                    $table->dropUnique(['level']);
                } catch (\Throwable) {
                    // Index name differs across sqlite/mysql.
                }
            }
            if (! Schema::hasColumn('instrument_templates', 'version')) {
                $table->unsignedInteger('version')->default(1)->after('level');
            }
            if (! Schema::hasColumn('instrument_templates', 'status')) {
                $table->string('status')->default('published')->after('version');
            }
            if (! Schema::hasColumn('instrument_templates', 'parent_id')) {
                $table->foreignId('parent_id')->nullable()->after('status')
                    ->constrained('instrument_templates')->nullOnDelete();
            }
        });

        Schema::table('accreditation_workspaces', function (Blueprint $table) {
            if (! Schema::hasColumn('accreditation_workspaces', 'accreditation_date')) {
                $table->date('accreditation_date')->nullable()->after('level');
            }
            if (! Schema::hasColumn('accreditation_workspaces', 'phase')) {
                $table->string('phase')->nullable()->after('deadline');
            }
            if (! Schema::hasColumn('accreditation_workspaces', 'template_version')) {
                $table->unsignedInteger('template_version')->nullable()->after('template_id');
            }
        });

        Schema::table('criterion_evidence', function (Blueprint $table) {
            if (! Schema::hasColumn('criterion_evidence', 'role_storage_file_id')) {
                $table->foreignId('role_storage_file_id')->nullable()->after('uploaded_by')
                    ->constrained('role_storage_files')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('criterion_evidence', function (Blueprint $table) {
            if (Schema::hasColumn('criterion_evidence', 'role_storage_file_id')) {
                $table->dropConstrainedForeignId('role_storage_file_id');
            }
        });

        Schema::table('accreditation_workspaces', function (Blueprint $table) {
            foreach (['accreditation_date', 'phase', 'template_version'] as $column) {
                if (Schema::hasColumn('accreditation_workspaces', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('instrument_templates', function (Blueprint $table) {
            if (Schema::hasColumn('instrument_templates', 'parent_id')) {
                $table->dropConstrainedForeignId('parent_id');
            }
            foreach (['version', 'status'] as $column) {
                if (Schema::hasColumn('instrument_templates', $column)) {
                    $table->dropColumn($column);
                }
            }
            $table->unique('level');
        });
    }
};
