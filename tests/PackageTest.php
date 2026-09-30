<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use PlinCode\PlatformAuthorizer\PlatformAuthorizerServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProvider(PlatformAuthorizerServiceProvider::class))->not->toBeNull();
});

it('publishes the configuration and the migration under the tags the readme names', function (string $tag) {
    expect(ServiceProvider::pathsToPublish(PlatformAuthorizerServiceProvider::class, $tag))->not->toBeEmpty();
})->with(['platform-authorizer-config', 'platform-authorizer-migrations']);

it('registers the routes of the round trip and the synchronisation command', function () {
    expect(Route::has('platform-authorizer.redirect'))->toBeTrue()
        ->and(Route::has('platform-authorizer.callback'))->toBeTrue()
        ->and(Artisan::all())->toHaveKey('platform-authorizer:sync-flags');
});

it('ships a configuration that reads nothing from the environment but the installation slug', function () {
    $source = (string) file_get_contents(__DIR__.'/../config/platform-authorizer.php');

    expect(preg_match_all('/env\(/', $source))->toBe(1)
        ->and($source)->toContain("env('PLATFORM_INSTALLATION')");
});
