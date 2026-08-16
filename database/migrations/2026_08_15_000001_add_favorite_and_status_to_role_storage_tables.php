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
        Schema::table('role_storage_files', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('file_path');
            $table->string('status')->default('active')->after('is_favorite');
            $table->timestamp('deleted_at')->nullable()->after('status');
        });

        Schema::table('role_storage_folders', function (Blueprint $table) {
            $table->boolean('is_favorite')->default(false)->after('name');
            $table->string('status')->default('active')->after('is_favorite');
            $table->timestamp('deleted_at')->nullable()->after('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('role_storage_files', function (Blueprint $table) {
            $table->dropColumn(['is_favorite', 'status', 'deleted_at']);
        });

        Schema::table('role_storage_folders', function (Blueprint $table) {
            $table->dropColumn(['is_favorite', 'status', 'deleted_at']);
        });
    }
};
