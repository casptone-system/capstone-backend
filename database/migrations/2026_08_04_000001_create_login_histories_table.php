<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

        if (Schema::getConnection()->getDriverName() === 'mysql') {
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
           // DB::unprepared('ALTER TABLE login_histories PARTITION BY RANGE (YEAR(occurred_at)) (PARTITION p2026 VALUES LESS THAN (2027), PARTITION p2027 VALUES LESS THAN (2028), PARTITION pmax VALUES LESS THAN MAXVALUE)');
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'mysql') {
            DB::unprepared('DROP TRIGGER IF EXISTS trg_login_histories_after_insert');
        }

        Schema::dropIfExists('login_histories');
    }
};
