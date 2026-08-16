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
            $table->foreignId('college_id')->nullable()->after('program_id')->constrained('colleges')->nullOnDelete();
            $table->string('phase')->nullable()->after('status');
            $table->string('instrument_name')->nullable()->after('phase');
            $table->foreignId('acknowledged_by')->nullable()->after('instrument_name')->constrained('users')->nullOnDelete();
            $table->timestamp('acknowledged_at')->nullable()->after('acknowledged_by');
            $table->foreignId('forwarded_by')->nullable()->after('acknowledged_at')->constrained('users')->nullOnDelete();
            $table->timestamp('forwarded_at')->nullable()->after('forwarded_by');
            $table->foreignId('program_chair_id')->nullable()->after('forwarded_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('accreditation_cycles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('program_chair_id');
            $table->dropConstrainedForeignId('forwarded_by');
            $table->dropColumn(['forwarded_at']);
            $table->dropConstrainedForeignId('acknowledged_by');
            $table->dropColumn(['acknowledged_at']);
            $table->dropConstrainedForeignId('college_id');
            $table->dropColumn(['phase', 'instrument_name']);
        });
    }
};
