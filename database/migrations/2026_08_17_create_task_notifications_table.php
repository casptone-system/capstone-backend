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
        Schema::create('task_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assigned_by_id')->constrained('users')->cascadeOnDelete(); // Dean who assigns
            $table->foreignId('assigned_to_id')->constrained('users')->cascadeOnDelete(); // Program Chair
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('document_upload'); // document_upload, review, approval, etc.
            $table->string('status')->default('pending'); // pending, viewed, completed, dismissed
            $table->foreignId('related_id')->nullable(); // ID of related model (document, review, etc.)
            $table->string('related_model')->nullable(); // Model class name
            $table->timestamp('viewed_at')->nullable(); // When the chair first viewed it
            $table->timestamp('badge_clear_at')->nullable(); // When badge should auto-clear
            $table->integer('badge_clear_hours')->default(48); // Hours before badge auto-clears
            $table->timestamps();
            
            $table->index('assigned_to_id');
            $table->index('status');
            $table->index('badge_clear_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_notifications');
    }
};
