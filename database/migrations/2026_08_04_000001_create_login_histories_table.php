<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('login_histories')) {
            Schema::create('login_histories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete()->index();
                $table->string('email')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('occurred_at')->useCurrent()->index();
                $table->timestamps();
                $table->enum('status', ['failed', 'success', 'logout'])
                    ->default('failed')
                    ->index();
            });
        }

        $this->createLoginHistoryTrigger();
    }

    public function down(): void
    {
        $this->dropLoginHistoryTrigger();
        Schema::dropIfExists('login_histories');
    }

    private function supportsMysqlTriggers(): bool
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return false;
        }

        try {
            $version = Schema::getConnection()->selectOne('SELECT VERSION() as v');
            $value = strtolower((string) ($version->v ?? ''));

            return ! str_contains($value, 'tidb');
        } catch (\Throwable) {
            return false;
        }
    }

    private function createLoginHistoryTrigger(): void
    {
        if (! $this->supportsMysqlTriggers()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_login_histories_after_insert');
        DB::unprepared(<<<'SQL'
CREATE TRIGGER trg_login_histories_after_insert
AFTER INSERT ON login_histories
FOR EACH ROW
BEGIN
    IF NEW.user_id IS NOT NULL THEN
        UPDATE users
        SET last_login_at = NEW.occurred_at
        WHERE id = NEW.user_id;
    END IF;
END
SQL
        );
    }

    private function dropLoginHistoryTrigger(): void
    {
        if (! $this->supportsMysqlTriggers()) {
            return;
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_login_histories_after_insert');
    }
};
