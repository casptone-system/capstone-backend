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
        Schema::table('accreditation_cycles', function (Blueprint $table) {
            // Add workflow_status column to track handoff states
            $table->string('workflow_status')->default('Initial Notice')->after('status');
        });

        // Migrate existing data: Copy phase values to workflow_status if phase is set
        \DB::statement('UPDATE accreditation_cycles SET workflow_status = COALESCE(phase, "Initial Notice")');

        // Now we can safely use phase only for accreditation phases
        // Reset phase to NULL so Program Chair can set it during setup
        \DB::statement('UPDATE accreditation_cycles SET phase = NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accreditation_cycles', function (Blueprint $table) {
            // Migrate data back: Copy workflow_status to phase
            \DB::statement('UPDATE accreditation_cycles SET phase = workflow_status');
            
            // Drop workflow_status column
            $table->dropColumn(['workflow_status']);
        });
    }
};
