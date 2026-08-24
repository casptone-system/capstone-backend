<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('active_cycle_id')
                ->nullable()
                ->after('chair_id')
                ->constrained('accreditation_cycles')
                ->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->foreignId('content_row_id')
                ->nullable()
                ->after('task_id')
                ->constrained('parameter_content_rows')
                ->nullOnDelete();
        });

        Schema::table('accreditation_areas', function (Blueprint $table) {
            $table->unsignedTinyInteger('progress_percent')->default(0)->after('status');
            $table->timestamp('progress_computed_at')->nullable()->after('progress_percent');
        });
    }

    public function down(): void
    {
        Schema::table('accreditation_areas', function (Blueprint $table) {
            $table->dropColumn(['progress_percent', 'progress_computed_at']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('content_row_id');
        });

        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('active_cycle_id');
        });
    }
};
