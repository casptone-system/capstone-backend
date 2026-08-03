<?php

namespace App\Providers;

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
    }
}
