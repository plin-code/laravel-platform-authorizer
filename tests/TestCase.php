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
        // Sessions and encrypted cookies need a key and a driver that works without a browser.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('session.driver', 'array');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
