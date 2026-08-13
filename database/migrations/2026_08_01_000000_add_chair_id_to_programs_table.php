<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->foreignId('chair_id')->nullable()->constrained('users')->nullOnDelete()->after('code');
        });

        // Preserve legacy chair assignments by linking existing user records to programs.
        DB::table('programs')
            ->whereNull('chair_id')
            ->whereNotNull('chair')
            ->orderBy('id')
            ->chunkById(100, function ($programs) {
                foreach ($programs as $program) {
                    $user = DB::table('users')
                        ->where('name', $program->chair)
                        ->first();

                    if ($user) {
                        DB::table('programs')
                            ->where('id', $program->id)
                            ->update(['chair_id' => $user->id]);
                    }
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('programs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('chair_id');
        });
    }
};
