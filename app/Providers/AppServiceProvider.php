<?php

namespace App\Providers;

use App\Models\AccreditationArea;
use App\Models\AuditLog;
use App\Models\College;
use App\Models\Document;
use App\Models\Invitation;
use App\Models\Program;
use App\Models\ProgramMember;
use App\Models\Review;
use App\Models\Task;
use App\Models\User;
use App\Policies\AccreditationAreaPolicy;
use App\Policies\AuditLogPolicy;
use App\Policies\DeanPolicy;
use App\Policies\DocumentPolicy;
use App\Policies\InvitationPolicy;
use App\Policies\ProgramMemberPolicy;
use App\Policies\ProgramPolicy;
use App\Policies\ReviewPolicy;
use App\Policies\TaskPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Log::extend('audit', function ($app, array $config) {
            return new \Monolog\Logger('audit', [
                new \Monolog\Handler\StreamHandler(storage_path('logs/audit.log'), \Monolog\Logger::INFO),
            ]);
        });

        // Register policies so that controller authorization can use them
        Gate::define('access-college-dashboard', function (User $user): bool {
            return $user->isDean() && (bool) $user->getEffectiveCollegeId();
        });

        Gate::define('monitor-college', function (User $user, ?College $college = null): bool {
            if (! $user->isDean()) {
                return false;
            }

            $effectiveCollegeId = $user->getEffectiveCollegeId();
            if (! $effectiveCollegeId) {
                return false;
            }

            if (! $college) {
                return true;
            }

            return (int) $college->id === (int) $effectiveCollegeId;
        });

        Gate::policy(Document::class, DocumentPolicy::class);
        Gate::policy(Review::class, ReviewPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(Program::class, ProgramPolicy::class);
        Gate::policy(Invitation::class, InvitationPolicy::class);
        Gate::policy(ProgramMember::class, ProgramMemberPolicy::class);
        Gate::policy(College::class, DeanPolicy::class);
    }
}
