<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the fixed AACCUP area code and per-area submission deadline
     * columns to the accreditation areas table.
     */
    public function up(): void
    {
        Schema::table('accreditation_areas', function (Blueprint $table) {
            if (! Schema::hasColumn('accreditation_areas', 'code')) {
                $table->string('code')->nullable()->after('name');
            }
            if (! Schema::hasColumn('accreditation_areas', 'deadline')) {
                $table->dateTime('deadline')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('accreditation_areas', function (Blueprint $table) {
            if (Schema::hasColumn('accreditation_areas', 'code')) {
                $table->dropColumn('code');
            }
            if (Schema::hasColumn('accreditation_areas', 'deadline')) {
                $table->dropColumn('deadline');
            }
        });
    }
};