<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use PlinCode\PlatformAuthorizer\Client\AuthorizerClient;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    // An application configures the real key and its own values; the fake
    // adds the key of the test signer next to them.
    config()->set('platform-authorizer', configFor(new Signer('the-real-seed', 'real-key'), ['keys' => [
        'real-key' => (new Signer('the-real-seed', 'real-key'))->publicKey(),
    ]]));
    Feature::define('check-in', fn () => false);
    $this->fake = FakeAuthorizer::install();
    $this->user = new GenericUser(['id' => 1, 'email' => 'staff@example.test']);
});

it('adds the test key to the configured ones and keeps the others', function () {
    expect(array_keys(config('platform-authorizer.keys')))->toBe(['real-key', $this->fake->signer()->keyId()]);
});

it('refuses to install itself outside the tests', function () {
    $this->app->instance('env', 'production');

    try {
        expect(fn () => FakeAuthorizer::install())->toThrow(LogicException::class);
    } finally {
        // The rollback that ends the test would ask for confirmation.
        $this->app->instance('env', 'testing');
    }
});

it('grants an authorization valid for the user', function () {
    $this->fake->grant($this->user);

    $this->actingAs($this->user);

    expect(app(PlatformAuthorization::class)->isGranted())->toBeTrue();

    $this->actingAs(new GenericUser(['id' => 2, 'email' => 'someone@example.test']));

    expect(app(PlatformAuthorization::class)->isGranted())->toBeFalse();
});

it('sets flags without an authorization, so a test can start from a known state', function () {
    $this->fake->setFlags(['check-in' => true]);

    expect(Feature::for('__global__')->active('check-in'))->toBeTrue()
        ->and($this->fake->flags())->toBe(['check-in' => true])
        ->and($this->fake->version())->toBe(1)
        ->and(app(ManifestRepository::class)->current()?->version)->toBe(1);

    $this->fake->setFlags(['check-in' => false]);

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse()
        ->and($this->fake->version())->toBe(2);
});

it('answers flag writes like the authorizer, one version per change', function () {
    $this->fake->grant();

    Feature::activateForEveryone('check-in');
    Feature::for('__global__')->activate('check-in');

    expect(Feature::for('__global__')->active('check-in'))->toBeTrue()
        ->and($this->fake->flags())->toBe(['check-in' => true])
        ->and($this->fake->version())->toBe(1)
        ->and($this->fake->writes())->toBe(1);
});

it('refuses writes like the authorizer does', function (array $claims, ?Signer $signer, string $exception) {
    $this->fake->grant(claims: $claims, signer: $signer);

    expect(fn () => Feature::for('__global__')->activate('check-in'))->toThrow($exception);
    expect($this->fake->writes())->toBe(0);
})->with([
    'an expired assertion' => [['exp' => now()->getTimestamp() - 3600], null, AuthorizationExpiredException::class],
    'an assertion of another key' => [[], new Signer('another-seed', 'other-key'), AuthorizationRejectedException::class],
]);

it('serves the latest manifest to the synchronisation', function () {
    $this->fake->setFlags(['check-in' => true]);
    DB::table('feature_manifests')->delete();

    $this->artisan('platform-authorizer:sync-flags')->assertExitCode(0);

    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['check-in' => true]);
});

it('reports that no manifest exists before the first one is issued', function () {
    $this->artisan('platform-authorizer:sync-flags')->expectsOutputToContain('No manifest')->assertExitCode(0);
});

it('records the events an installation sends', function () {
    app(AuthorizerClient::class)->reportTamper('a decoy key was used');

    expect($this->fake->events())->toBe([['type' => 'tamper', 'reason' => 'a decoy key was used']]);
});
