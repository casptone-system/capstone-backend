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
        Schema::create('designation_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('area_id')->nullable()->constrained('accreditation_areas')->nullOnDelete();
            $table->foreignId('designated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role');
            $table->string('role_label');
            $table->string('area_label');
            $table->string('program_name');
            $table->string('level')->nullable();
            $table->string('place_name');
            $table->string('signer_name')->nullable();
            $table->date('designation_date');
            $table->date('valid_until')->nullable();
            $table->string('file_path');
            $table->string('original_name');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('designation_files');
    }
};
