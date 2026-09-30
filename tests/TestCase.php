<?php

namespace PlinCode\PlatformAuthorizer\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\PennantServiceProvider;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;
use PlinCode\PlatformAuthorizer\PlatformAuthorizerServiceProvider;
use PlinCode\PlatformAuthorizer\Tests\Elsewhere\OtherPanel;
use PlinCode\PlatformAuthorizer\Tests\Fixtures\ProbePanel;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('probe-panel', ProbePanel::class);
        Livewire::component('other-panel', OtherPanel::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
            PennantServiceProvider::class,
            PlatformAuthorizerServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        config()->set('database.default', 'testing');
        config()->set('session.driver', 'array');
        // Flags are read and written through the package driver, never through
        // Pennant's own database store.
        config()->set('pennant.default', 'platform-authorizer');
        config()->set('pennant.stores', ['platform-authorizer' => ['driver' => 'platform-authorizer']]);
        // The web middleware group encrypts cookies, which needs a key.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function defineRoutes($router): void
    {
        // The auth middleware sends guests to a route named login.
        $router->get('/login', fn () => 'login')->name('login');

        // A protected page that hosts a Livewire component, and a route the
        // middleware has to let through even without an authorization.
        Route::middleware(['web', RequirePlatformAuthorization::class])->group(function (): void {
            Route::get('/probe', fn () => Blade::render('<html><body><livewire:probe-panel /></body></html>'))->name('probe');
            Route::post('/probe/logout', fn () => 'logged out')->name('probe.logout');
        });
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
