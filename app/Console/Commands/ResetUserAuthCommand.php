<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use App\Support\LoginChallenge;
use Illuminate\Console\Command;

class ResetUserAuthCommand extends Command
{
    protected $signature = 'auth:reset-user {email : The account email to unlock}';

    protected $description = 'Mark an account email verified and clear pending login challenges';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if (! $user) {
            $this->error('No account found for that email.');

            return self::FAILURE;
        }

        $user->forceFill(['email_verified_at' => $user->email_verified_at ?? now()])->save();
        LoginChallenge::forgetForUser((int) $user->id);
        LoginChallenge::clearLockout((int) $user->id);

        AuditLog::create([
            'user_id' => $user->id,
            'user_email' => $user->email,
            'event' => 'AUTH_BREAK_GLASS_RESET',
            'method' => 'CLI',
            'path' => 'auth:reset-user',
            'status' => 'success',
            'ip_address' => 'cli',
        ]);

        $this->info('Email marked verified and pending login challenges cleared for ' . $user->email . '.');

        return self::SUCCESS;
    }
}
