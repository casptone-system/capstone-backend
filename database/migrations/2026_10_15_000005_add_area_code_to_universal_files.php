<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('universal_files', function (Blueprint $table) {
            $table->string('area_code', 32)->default('general')->after('notes')->index();
        });
    }

    public function down(): void
    {
        Schema::table('universal_files', function (Blueprint $table) {
            $table->dropIndex(['area_code']);
            $table->dropColumn('area_code');
        });
    }
};
