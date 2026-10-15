<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('board_of_action_documents', 'action_date')) {
            return;
        }

        Schema::table('board_of_action_documents', function (Blueprint $table) {
            $table->index('program_id', 'board_of_action_documents_program_id_index');
        });

        Schema::table('board_of_action_documents', function (Blueprint $table) {
            $table->dropIndex(['program_id', 'action_date']);
            $table->dropColumn('action_date');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('board_of_action_documents', 'action_date')) {
            return;
        }

        Schema::table('board_of_action_documents', function (Blueprint $table) {
            $table->date('action_date')->nullable()->after('title');
            $table->index(['program_id', 'action_date']);
        });
    }
};
