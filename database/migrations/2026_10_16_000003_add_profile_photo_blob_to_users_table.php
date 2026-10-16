<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'profile_photo_blob')) {
            Schema::table('users', function (Blueprint $table) {
                // Survive Render's ephemeral disk across redeploys.
                $table->mediumBlob('profile_photo_blob')->nullable()->after('profile_photo');
                $table->string('profile_photo_mime', 100)->nullable()->after('profile_photo_blob');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'profile_photo_blob')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn(['profile_photo_blob', 'profile_photo_mime']);
            });
        }
    }
};
