<?php

namespace Tests;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        putenv('SESSION_DRIVER=array');
        putenv('CACHE_DRIVER=array');
        putenv('QUEUE_CONNECTION=sync');
        putenv('MAIL_MAILER=log');
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        parent::setUp();

        $this->app['config']->set('session.driver', 'array');
        $this->app['config']->set('cache.default', 'array');
        $this->app['config']->set('queue.default', 'sync');
        $this->app['config']->set('mail.default', 'log');
        $this->app['config']->set('database.default', 'sqlite');
        $this->app['config']->set('database.connections.sqlite.database', ':memory:');

        Cache::flush();

        if ($this->app->environment('testing') && Schema::hasTable('permissions')) {
            $this->seed(RolePermissionSeeder::class);
        }
    }
}
