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
        Schema::table('programs', function (Blueprint $table) {
            // Add accreditation configuration fields
            $table->string('accreditation_level')->nullable()->after('compliance_score');
            $table->string('accreditation_phase')->nullable()->after('accreditation_level');
            $table->date('scheduled_visit')->nullable()->after('accreditation_phase');
            $table->date('valid_until')->nullable()->after('scheduled_visit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropColumn([
                'accreditation_level',
                'accreditation_phase',
                'scheduled_visit',
                'valid_until',
            ]);
        });
    }
};
