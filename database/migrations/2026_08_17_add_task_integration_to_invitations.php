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
        Schema::table('invitations', function (Blueprint $table) {
            // Track if a welcome task should be sent
            $table->boolean('send_welcome_task')->default(true)->after('status');
            // Store related task notification
            $table->foreignId('welcome_task_id')->nullable()->after('send_welcome_task')
                ->constrained('task_notifications')->nullOnDelete();
        });

        Schema::table('task_notifications', function (Blueprint $table) {
            // Track if this is a welcome/onboarding task
            $table->boolean('is_welcome_task')->default(false)->after('type');
            // Link to invitation if created from one
            $table->foreignId('invitation_id')->nullable()->after('is_welcome_task')
                ->constrained('invitations')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('task_notifications', function (Blueprint $table) {
            $table->dropForeignIdFor('invitations');
            $table->dropColumn('invitation_id');
            $table->dropColumn('is_welcome_task');
        });

        Schema::table('invitations', function (Blueprint $table) {
            $table->dropForeignIdFor('task_notifications');
            $table->dropColumn('welcome_task_id');
            $table->dropColumn('send_welcome_task');
        });
    }
};
