<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['accreditation_cycle_id']);
            $table->foreignId('accreditation_cycle_id')->nullable()->change();
            $table->foreign('accreditation_cycle_id')->references('id')->on('accreditation_cycles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropForeign(['accreditation_cycle_id']);
            $table->foreignId('accreditation_cycle_id')->nullable(false)->change();
            $table->foreign('accreditation_cycle_id')->references('id')->on('accreditation_cycles')->cascadeOnDelete();
        });
    }
};
