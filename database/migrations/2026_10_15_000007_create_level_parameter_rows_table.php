<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('level_parameter_rows', function (Blueprint $table) {
            $table->id();
            $table->string('level', 32);
            $table->string('area_code', 32);
            $table->string('parameter_code', 32);
            $table->text('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['level', 'area_code', 'parameter_code', 'sort_order'], 'level_parameter_rows_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('level_parameter_rows');
    }
};
