<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop unused messaging/invitation scaffolding and orphan task tables.
     * Live flows use team_join_codes, task_notifications, and task_assignments.
     */
    public function up(): void
    {
        if (Schema::hasTable('task_notifications') && Schema::hasColumn('task_notifications', 'invitation_id')) {
            Schema::table('task_notifications', function (Blueprint $table) {
                $table->dropConstrainedForeignId('invitation_id');
            });
        }

        Schema::dropIfExists('message_attachments');
        Schema::dropIfExists('conversation_participants');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('invitations');

        // Orphan tables present in some DBs but never created by current migrations.
        // Drop child submissions before parent requirements (FK).
        Schema::dropIfExists('task_evidence_submissions');
        Schema::dropIfExists('task_evidence_requirements');
        Schema::dropIfExists('tasks_assignments');
    }

    public function down(): void
    {
        if (! Schema::hasTable('invitations')) {
            Schema::create('invitations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('program_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
                $table->string('email')->nullable();
                $table->string('role')->nullable();
                $table->string('token', 64)->unique();
                $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('expires_at')->nullable();
                $table->timestamp('accepted_at')->nullable();
                $table->enum('status', ['pending', 'requested', 'accepted', 'expired', 'revoked'])->default('pending');
                $table->boolean('send_welcome_task')->default(true);
                $table->foreignId('welcome_task_id')->nullable()
                    ->constrained('task_notifications')->nullOnDelete();
                $table->timestamps();
            });
        }

        if (Schema::hasTable('task_notifications') && ! Schema::hasColumn('task_notifications', 'invitation_id')) {
            Schema::table('task_notifications', function (Blueprint $table) {
                $table->foreignId('invitation_id')->nullable()
                    ->constrained('invitations')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('conversations')) {
            Schema::create('conversations', function (Blueprint $table) {
                $table->id();
                $table->foreignId('accreditation_cycle_id')->nullable()->constrained()->nullOnDelete();
                $table->string('subject');
                $table->string('type');
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->foreignId('area_id')->nullable()->constrained('accreditation_areas')->nullOnDelete();
                $table->foreignId('parameter_id')->nullable()->constrained('accreditation_parameters')->nullOnDelete();
                $table->foreignId('workspace_id')->nullable()->constrained('accreditation_workspaces')->nullOnDelete();
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('messages')) {
            Schema::create('messages', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sender_id')->constrained('users')->cascadeOnDelete();
                $table->longText('body');
                $table->timestamps();
                $table->softDeletes();
            });
        }

        if (! Schema::hasTable('conversation_participants')) {
            Schema::create('conversation_participants', function (Blueprint $table) {
                $table->id();
                $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->timestamp('last_read_at')->nullable();
                $table->boolean('is_archived')->default(false);
                $table->timestamps();
                $table->unique(['conversation_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('message_attachments')) {
            Schema::create('message_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('message_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_storage_file_id')->nullable()->constrained()->nullOnDelete();
                $table->string('file_name');
                $table->string('file_path');
                $table->string('file_mime');
                $table->unsignedBigInteger('file_size');
                $table->timestamps();
            });
        }
    }
};
