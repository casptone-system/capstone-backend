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
        if (Schema::hasTable('invitations')) {
            Schema::table('invitations', function (Blueprint $table) {
                if (!Schema::hasColumn('invitations', 'send_welcome_task')) {
                    $table->boolean('send_welcome_task')->default(true)->after('status');
                }

                if (!Schema::hasColumn('invitations', 'welcome_task_id') && Schema::hasTable('task_notifications')) {
                    $table->foreignId('welcome_task_id')->nullable()->after('send_welcome_task')
                        ->constrained('task_notifications')->nullOnDelete();
                }
            });
        }

        if (Schema::hasTable('task_notifications')) {
            Schema::table('task_notifications', function (Blueprint $table) {
            if (!Schema::hasColumn('task_notifications', 'is_welcome_task')) {
                // Track if this is a welcome/onboarding task
                $table->boolean('is_welcome_task')->default(false)->after('type');
            }

            if (!Schema::hasColumn('task_notifications', 'invitation_id')) {
                // Link to invitation if created from one
                $table->foreignId('invitation_id')->nullable()->after('is_welcome_task')
                    ->constrained('invitations')->nullOnDelete();
            }
        });
        }
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
