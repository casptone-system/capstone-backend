<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('accreditation_instruments')) {
            Schema::create('accreditation_instruments', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('version')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('accreditation_cycles', 'instrument_id')) {
            Schema::table('accreditation_cycles', function (Blueprint $table) {
                $table->foreignId('instrument_id')->nullable()->after('program_id')
                    ->constrained('accreditation_instruments')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('accreditation_areas', 'instrument_id')) {
            Schema::table('accreditation_areas', function (Blueprint $table) {
                $table->foreignId('instrument_id')->nullable()->after('cycle_id')
                    ->constrained('accreditation_instruments')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('accreditation_requirements')) {
            Schema::create('accreditation_requirements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('area_id')->constrained('accreditation_areas')->cascadeOnDelete();
                $table->string('code');
                $table->string('title');
                $table->text('description')->nullable();
                $table->text('evidence_guidance')->nullable();
                $table->string('required_evidence_type')->nullable();
                $table->string('status')->default('Not Started');
                $table->timestamps();
                $table->unique(['area_id', 'code']);
            });
        } else {
            Schema::table('accreditation_requirements', function (Blueprint $table) {
                if (! Schema::hasColumn('accreditation_requirements', 'evidence_guidance')) {
                    $table->text('evidence_guidance')->nullable()->after('description');
                }
                if (! Schema::hasColumn('accreditation_requirements', 'required_evidence_type')) {
                    $table->string('required_evidence_type')->nullable()->after('evidence_guidance');
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('accreditation_requirements');
        if (Schema::hasColumn('accreditation_areas', 'instrument_id')) {
            Schema::table('accreditation_areas', function (Blueprint $table) {
                $table->dropConstrainedForeignId('instrument_id');
            });
        }
        if (Schema::hasColumn('accreditation_cycles', 'instrument_id')) {
            Schema::table('accreditation_cycles', function (Blueprint $table) {
                $table->dropConstrainedForeignId('instrument_id');
            });
        }
        Schema::dropIfExists('accreditation_instruments');
    }
};
