<?php

namespace PlinCode\PlatformAuthorizer;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PlatformAuthorizerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-platform-authorizer')
            ->hasConfigFile('platform-authorizer');
    }

    public function packageRegistered(): void
    {
        // Resolved on demand: an application that has not been configured yet
        // must still be able to run artisan commands.
        $this->app->bind(Settings::class, fn (): Settings => Settings::fromConfig((array) config('platform-authorizer')));
    }
}
