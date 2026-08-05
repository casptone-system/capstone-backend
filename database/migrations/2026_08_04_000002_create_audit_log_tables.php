<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('user_email')->nullable()->index();
            $table->string('event')->index();
            $table->string('method', 10)->nullable();
            $table->string('path')->index();

            $table->enum('status', [
                'success',
                'error',
                'warning',
                'info',
                'unauthorized',
                'forbidden'
            ])->nullable()->index();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->timestamp('created_at')->useCurrent()->index();
            $table->timestamp('updated_at')->useCurrent()->useCurrentOnUpdate();

            $table->unique(
                ['user_id', 'event', 'created_at'],
                'audit_logs_user_event_created_unique'
            );
        });

        Schema::create('audit_log_details', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('audit_log_id')->index();

            $table->text('user_agent')->nullable();
            $table->text('exception')->nullable();

            $table->timestamps();
        });

        Schema::create('audit_log_summaries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable()->index();

            $table->string('event')->index();

            $table->unsignedBigInteger('total_count')->default(0);

            $table->timestamp('last_occurred_at')->nullable()->index();

            $table->timestamps();

            $table->unique(['user_id', 'event']);
        });

        if (Schema::getConnection()->getDriverName() === 'mysql') {

            DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_after_insert');

            DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_audit_logs_after_insert
AFTER INSERT ON audit_logs
FOR EACH ROW
BEGIN
    INSERT INTO audit_log_summaries
    (user_id, event, total_count, last_occurred_at, created_at, updated_at)
    VALUES
    (NEW.user_id, NEW.event, 1, NEW.created_at, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        total_count = total_count + 1,
        last_occurred_at = NEW.created_at,
        updated_at = NOW();
END
SQL);
        }
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_logs_after_insert');

        Schema::dropIfExists('audit_log_details');
        Schema::dropIfExists('audit_log_summaries');
        Schema::dropIfExists('audit_logs');
    }
};