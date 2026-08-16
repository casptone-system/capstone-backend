<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            // Add accreditation-specific fields
            $table->foreignId('accreditation_cycle_id')->nullable()->after('area_id')->constrained('accreditation_cycles')->nullOnDelete();
            $table->foreignId('program_id')->nullable()->after('accreditation_cycle_id')->constrained('programs')->nullOnDelete();
            $table->foreignId('requirement_id')->nullable()->after('program_id')->constrained('accreditation_requirements')->nullOnDelete();
            $table->foreignId('assigned_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->text('instructions')->nullable()->after('description');
            $table->date('deadline')->nullable()->after('due_date');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeignIdFor('accreditation_cycles');
            $table->dropForeignIdFor('programs');
            $table->dropForeignIdFor('accreditation_requirements');
            $table->dropColumn(['accreditation_cycle_id', 'program_id', 'requirement_id', 'assigned_by', 'instructions', 'deadline']);
        });
    }
};
