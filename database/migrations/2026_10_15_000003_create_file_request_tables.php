<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('file_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('requester_program_id')->constrained('programs');
            $table->foreignId('requester_user_id')->constrained('users');
            $table->foreignId('recipient_program_id')->constrained('programs');
            $table->foreignId('recipient_user_id')->nullable()->constrained('users');
            $table->string('title');
            $table->text('description')->nullable();
            $table->date('needed_by')->nullable();
            $table->foreignId('related_area_id')->nullable()->constrained('accreditation_areas')->nullOnDelete();
            $table->string('status', 20)->default('pending');
            $table->text('decline_reason')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('needed_by_reminded_at')->nullable();
            $table->string('open_key', 320)->nullable();
            $table->timestamps();

            $table->index('recipient_program_id');
            $table->index('requester_program_id');
            $table->index('status');
            $table->index(['status', 'needed_by']);
            $table->unique('open_key');
        });

        Schema::create('file_request_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('file_request_id')->constrained('file_requests')->cascadeOnDelete();
            $table->string('file_path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('file_size');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('file_request_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('file_request_files');
        Schema::dropIfExists('file_requests');
    }
};
