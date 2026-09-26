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
        Schema::create('parameter_row_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_row_id')->constrained('parameter_content_rows')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('author_role')->nullable();
            $table->text('body');
            // 'comment' = ordinary thread post, 'revision_request' = mirrored from
            // DocumentController::requestRevision so the Return reason is preserved.
            $table->string('source')->default('comment');
            $table->foreignId('document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamps();

            $table->index(['content_row_id', 'id']);
        });

        Schema::create('parameter_row_comment_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('content_row_id')->constrained('parameter_content_rows')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('last_read_at')->nullable();
            $table->timestamps();

            $table->unique(['content_row_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('parameter_row_comment_reads');
        Schema::dropIfExists('parameter_row_comments');
    }
};
