<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('chunked_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('purpose'); // document | role_storage
            $table->string('original_name');
            $table->string('mime_type')->nullable();
            $table->string('extension', 32)->nullable();
            $table->unsignedBigInteger('total_size');
            $table->unsignedInteger('chunk_size');
            $table->unsignedInteger('total_chunks');
            $table->json('received_chunks')->nullable();
            $table->string('status')->default('pending');
            $table->string('checksum', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('storage_migration_items', function (Blueprint $table) {
            $table->id();
            $table->string('source_type');
            $table->unsignedBigInteger('source_id');
            $table->string('file_path');
            $table->unsignedBigInteger('file_size')->nullable();
            $table->string('direction')->default('to_r2');
            $table->string('status')->default('pending');
            $table->string('source_checksum', 64)->nullable();
            $table->string('destination_checksum', 64)->nullable();
            $table->text('error')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_id', 'direction']);
            $table->index(['status', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_migration_items');
        Schema::dropIfExists('chunked_uploads');
    }
};
