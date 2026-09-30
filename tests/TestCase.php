<?php

namespace PlinCode\PlatformAuthorizer\Tests;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;
use PlinCode\PlatformAuthorizer\PlatformAuthorizerServiceProvider;
use PlinCode\PlatformAuthorizer\Tests\Fixtures\ProbePanel;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Livewire::component('probe-panel', ProbePanel::class);
    }

    protected function getPackageProviders($app): array
    {
        return [
            LivewireServiceProvider::class,
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
