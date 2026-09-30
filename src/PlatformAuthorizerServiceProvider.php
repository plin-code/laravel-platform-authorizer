<?php

namespace PlinCode\PlatformAuthorizer;

use Illuminate\Contracts\Foundation\Application;
use Laravel\Pennant\FeatureManager;
use Livewire\Component;
use Livewire\Livewire;
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;
use PlinCode\PlatformAuthorizer\Http\ProtectedComponentGuard;
use PlinCode\PlatformAuthorizer\Manifests\ManifestCache;
use PlinCode\PlatformAuthorizer\Pennant\SignedFeatureDriver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

use function Livewire\on;

class PlatformAuthorizerServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-platform-authorizer')
            ->hasConfigFile('platform-authorizer')
            ->hasViews()
            ->hasTranslations()
            ->hasRoute('web')
            ->hasMigration('create_feature_manifests_table');
    }

    public function packageBooted(): void
    {
        // The driver exists only where Pennant is installed. Registering it
        // here, before the application defines its features, makes it
        // available under the name the Pennant configuration uses.
        if (class_exists(FeatureManager::class)) {
            $this->callAfterResolving(FeatureManager::class, function (FeatureManager $manager): void {
                $manager->extend('platform-authorizer', fn (Application $app): SignedFeatureDriver => new SignedFeatureDriver($app));
            });
        }

        // Livewire replays this middleware on update requests of the routes
        // that use it, so a page already open is checked again on every call.
        if (class_exists(Livewire::class)) {
            Livewire::addPersistentMiddleware([RequirePlatformAuthorization::class]);
            on('hydrate', fn (Component $component) => ProtectedComponentGuard::check($component));
        }
    }

    public function packageRegistered(): void
    {
        $this->app->scoped(ManifestCache::class);

        // Resolved on demand: an application that has not been configured yet
        // must still be able to run artisan commands.
        $this->app->singleton(PlatformAuthorization::class);

        $this->app->bind(Settings::class, fn (): Settings => Settings::fromConfig((array) config('platform-authorizer')));
    }
}
