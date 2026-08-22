<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('parameter_content_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parameter_id')->constrained('accreditation_parameters')->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['parameter_id', 'sort_order']);
        });

        Schema::create('parameter_row_statuses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_row_id')->constrained('parameter_content_rows')->cascadeOnDelete();
            $table->boolean('is_done')->default(false);
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->unique('content_row_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parameter_row_statuses');
        Schema::dropIfExists('parameter_content_rows');
    }
};
