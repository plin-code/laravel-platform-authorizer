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
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
