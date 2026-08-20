<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('instrument_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('level');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique('level');
        });

        Schema::create('instrument_template_areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('template_id')->constrained('instrument_templates')->cascadeOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('instrument_template_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('instrument_template_areas')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('instrument_template_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parameter_id')->constrained('instrument_template_parameters')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('evidence_type')->default('system');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('accreditation_parameters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('area_id')->constrained('accreditation_areas')->cascadeOnDelete();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('accreditation_workspaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->foreignId('cycle_id')->nullable()->constrained('accreditation_cycles')->nullOnDelete();
            $table->foreignId('template_id')->nullable()->constrained('instrument_templates')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('root_folder_id')->nullable()->constrained('role_storage_folders')->nullOnDelete();
            $table->string('name');
            $table->string('level');
            $table->date('deadline')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        Schema::create('criterion_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requirement_id')->constrained('accreditation_requirements')->cascadeOnDelete();
            $table->foreignId('parameter_id')->nullable()->constrained('accreditation_parameters')->nullOnDelete();
            $table->foreignId('area_id')->constrained('accreditation_areas')->cascadeOnDelete();
            $table->foreignId('workspace_id')->nullable()->constrained('accreditation_workspaces')->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('evidence_type')->default('system');
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->boolean('is_done')->default(false);
            $table->timestamp('marked_done_at')->nullable();
            $table->timestamps();
        });

        Schema::table('accreditation_requirements', function (Blueprint $table) {
            if (! Schema::hasColumn('accreditation_requirements', 'parameter_id')) {
                $table->foreignId('parameter_id')->nullable()->after('area_id')
                    ->constrained('accreditation_parameters')->nullOnDelete();
            }
        });

        Schema::table('role_storage_folders', function (Blueprint $table) {
            if (! Schema::hasColumn('role_storage_folders', 'workspace_id')) {
                $table->foreignId('workspace_id')->nullable()->after('parent_id')
                    ->constrained('accreditation_workspaces')->nullOnDelete();
            }
            if (! Schema::hasColumn('role_storage_folders', 'area_id')) {
                $table->foreignId('area_id')->nullable()->after('workspace_id')
                    ->constrained('accreditation_areas')->nullOnDelete();
            }
            if (! Schema::hasColumn('role_storage_folders', 'parameter_id')) {
                $table->foreignId('parameter_id')->nullable()->after('area_id')
                    ->constrained('accreditation_parameters')->nullOnDelete();
            }
            if (! Schema::hasColumn('role_storage_folders', 'folder_kind')) {
                $table->string('folder_kind')->nullable()->after('name');
            }
            if (! Schema::hasColumn('role_storage_folders', 'program_id')) {
                $table->foreignId('program_id')->nullable()->after('user_id')
                    ->constrained('programs')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('role_storage_folders', function (Blueprint $table) {
            if (Schema::hasColumn('role_storage_folders', 'parameter_id')) {
                $table->dropConstrainedForeignId('parameter_id');
            }
            if (Schema::hasColumn('role_storage_folders', 'area_id')) {
                $table->dropConstrainedForeignId('area_id');
            }
            if (Schema::hasColumn('role_storage_folders', 'workspace_id')) {
                $table->dropConstrainedForeignId('workspace_id');
            }
            if (Schema::hasColumn('role_storage_folders', 'program_id')) {
                $table->dropConstrainedForeignId('program_id');
            }
            if (Schema::hasColumn('role_storage_folders', 'folder_kind')) {
                $table->dropColumn('folder_kind');
            }
        });

        Schema::table('accreditation_requirements', function (Blueprint $table) {
            if (Schema::hasColumn('accreditation_requirements', 'parameter_id')) {
                $table->dropConstrainedForeignId('parameter_id');
            }
        });

        Schema::dropIfExists('criterion_evidence');
        Schema::dropIfExists('accreditation_workspaces');
        Schema::dropIfExists('accreditation_parameters');
        Schema::dropIfExists('instrument_template_criteria');
        Schema::dropIfExists('instrument_template_parameters');
        Schema::dropIfExists('instrument_template_areas');
        Schema::dropIfExists('instrument_templates');
    }
};
