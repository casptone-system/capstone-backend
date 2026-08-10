<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        // Set env early so the application boots with test configuration
        putenv('SESSION_DRIVER=array');
        putenv('CACHE_DRIVER=array');
        putenv('QUEUE_CONNECTION=sync');
        putenv('MAIL_MAILER=log');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        parent::setUp();

        // Ensure a deterministic, in-memory environment for tests
        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('mail.default', 'log');

        // Use sqlite in-memory for tests to avoid external DB dependencies
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', ':memory:');

        // Reset cache between tests
        \Illuminate\Support\Facades\Cache::flush();

        // Do not disable middleware globally; allow feature tests to exercise middleware

        if ($this->app->environment('testing') && Schema::hasTable('permissions')) {
            $this->seed(RolePermissionSeeder::class);
        }
    }
}
