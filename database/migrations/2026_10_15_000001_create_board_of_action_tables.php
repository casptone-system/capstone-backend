<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_of_action_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('program_id')->constrained('programs')->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('current_version')->default(1);
            $table->timestamps();

            $table->index('program_id');
        });

        Schema::create('board_of_action_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('board_of_action_document_id')
                ->constrained('board_of_action_documents')
                ->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['board_of_action_document_id', 'version'], 'boa_versions_document_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_of_action_versions');
        Schema::dropIfExists('board_of_action_documents');
    }
};
