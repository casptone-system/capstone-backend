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
        Schema::table('area_members', function (Blueprint $table) {
            // Add indexes for frequently queried columns
            $table->index('area_id');
            $table->index('user_id');
            $table->index('role');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('area_members', function (Blueprint $table) {
            $table->dropIndex(['area_id']);
            $table->dropIndex(['user_id']);
            $table->dropIndex(['role']);
        });
    }
};
