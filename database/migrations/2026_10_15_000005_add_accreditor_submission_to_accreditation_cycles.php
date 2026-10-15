<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accreditation_cycles', function (Blueprint $table) {
            $table->timestamp('submitted_to_accreditor_at')->nullable()->after('program_chair_id');
            $table->foreignId('submitted_to_accreditor_by')
                ->nullable()
                ->after('submitted_to_accreditor_at')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('accreditation_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submitted_to_accreditor_by');
            $table->dropColumn('submitted_to_accreditor_at');
        });
    }
};
