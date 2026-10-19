<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'profile_photo_blob')) {
            $driver = Schema::getConnection()->getDriverName();
            if (in_array($driver, ['mysql', 'mariadb'], true)) {
                // TiDB/MySQL MEDIUMBLOB via raw SQL — Blueprint has no mediumBlob().
                DB::statement('ALTER TABLE `users` ADD `profile_photo_blob` MEDIUMBLOB NULL AFTER `profile_photo`');
            } else {
                // SQLite (tests): binary column without AFTER / MEDIUMBLOB.
                Schema::table('users', function (Blueprint $table) {
                    $table->binary('profile_photo_blob')->nullable();
                });
            }
        }

        if (! Schema::hasColumn('users', 'profile_photo_mime')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('profile_photo_mime', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'profile_photo_mime')) {
                $table->dropColumn('profile_photo_mime');
            }
            if (Schema::hasColumn('users', 'profile_photo_blob')) {
                $table->dropColumn('profile_photo_blob');
            }
        });
    }
};
