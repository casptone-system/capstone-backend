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
        // Add files_enabled and file_folder_path to task_notifications
        Schema::table('task_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('task_notifications', 'files_enabled')) {
                $table->boolean('files_enabled')->default(false)->after('is_welcome_task');
            }
            if (!Schema::hasColumn('task_notifications', 'file_folder_path')) {
                $table->string('file_folder_path')->nullable()->after('files_enabled');
            }
        });

        // Create task_notification_files table to store attachments
        Schema::create('task_notification_files', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_notification_id');
            $table->foreign('task_notification_id', 'tnf_task_notification_fk')
                  ->references('id')->on('task_notifications')->onDelete('cascade');
            $table->unsignedBigInteger('document_id')->nullable();
            $table->foreign('document_id', 'tnf_document_fk')
                  ->references('id')->on('documents')->onDelete('set null');
            $table->string('file_name');
            $table->string('file_path');
            $table->string('mime_type')->default('application/octet-stream');
            $table->bigInteger('file_size')->default(0);
            $table->string('file_type')->default('instrument');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['task_notification_id', 'file_type'], 'tnf_type_idx');
        });

        // Create task_notification_file_forwards table for file forwarding
        Schema::create('task_notification_file_forwards', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('task_notification_id');
            $table->foreign('task_notification_id', 'tnff_task_notif_fk')
                  ->references('id')->on('task_notifications')->onDelete('cascade');
            $table->unsignedBigInteger('task_notification_file_id');
            $table->foreign('task_notification_file_id', 'tnff_file_fk')
                  ->references('id')->on('task_notification_files')->onDelete('cascade');
            $table->unsignedBigInteger('from_user_id');
            $table->foreign('from_user_id', 'tnff_from_user_fk')
                  ->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('to_user_id');
            $table->foreign('to_user_id', 'tnff_to_user_fk')
                  ->references('id')->on('users')->onDelete('cascade');
            $table->text('message')->nullable();
            $table->timestamp('forwarded_at')->useCurrent();
            $table->timestamps();
            $table->index(['task_notification_id', 'to_user_id'], 'tnff_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_notification_file_forwards');
        Schema::dropIfExists('task_notification_files');
        Schema::table('task_notifications', function (Blueprint $table) {
            $table->dropColumn(['files_enabled', 'file_folder_path']);
        });
    }
};
