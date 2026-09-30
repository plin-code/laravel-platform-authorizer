<?php

namespace PlinCode\PlatformAuthorizer\Tests;

use Orchestra\Testbench\TestCase as Orchestra;
use PlinCode\PlatformAuthorizer\PlatformAuthorizerServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            PlatformAuthorizerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('session.driver', 'array');
        // The web middleware group encrypts cookies, which needs a key.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        // The auth middleware sends guests to a route named login.
        $router->get('/login', fn () => 'login')->name('login');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
