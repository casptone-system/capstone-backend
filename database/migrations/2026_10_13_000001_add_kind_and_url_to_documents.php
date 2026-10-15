<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->string('kind', 16)->default('file')->after('title');
            $table->string('url', 2048)->nullable()->after('kind');
            $table->index(['content_row_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['content_row_id', 'kind']);
            $table->dropColumn(['kind', 'url']);
        });
    }
};
