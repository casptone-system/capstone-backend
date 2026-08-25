<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('colleges') || Schema::hasColumn('colleges', 'campus')) {
            return;
        }

        Schema::table('colleges', function (Blueprint $table) {
            $table->string('campus')->nullable()->after('code');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('colleges') || ! Schema::hasColumn('colleges', 'campus')) {
            return;
        }

        Schema::table('colleges', function (Blueprint $table) {
            $table->dropColumn('campus');
        });
    }
};
