<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\UnsupportedScopeException;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\Pennant\SignedFeatureDriver;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;
use PlinCode\PlatformAuthorizer\Tests\Fixtures\Person;

beforeEach(function () {
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->startSession();
    $this->actingAs(new GenericUser(['id' => 1, 'email' => 'vendor@example.test']));

    Feature::define('check-in', fn () => false);
    Feature::define('kill-switch', fn () => true);
    Feature::define('for-x', fn (mixed $scope) => $scope === 'x');
});

/** Stores a manifest in the table without going through the repository. */
function putManifest(string $token): void
{
    DB::table('feature_manifests')->upsert(
        [['installation' => 'mizuno-acme', 'token' => $token, 'created_at' => now(), 'updated_at' => now()]],
        ['installation'],
        ['token'],
    );
    Feature::flushCache();
}

/**
 * Makes the authorizer answer every write with a manifest of the flags it
 * received, one version after the other.
 */
function authorizerSigns(Signer $signer, int $startingAt = 0): void
{
    $version = $startingAt;

    Http::fake(['*' => function (Request $request) use ($signer, &$version) {
        return Http::response(['manifest' => $signer->sign(manifestClaims(['ver' => ++$version, 'flags' => $request->data()['flags']]))]);
    }]);
}

function grantSession(): void
{
    app(PlatformAuthorization::class)->grant(test()->signer->sign(assertionClaims()));
}

it('answers with the shipped default while there is no manifest', function () {
    expect(Feature::for('__global__')->active('check-in'))->toBeFalse()
        ->and(Feature::for('__global__')->active('kill-switch'))->toBeTrue();
});

it('answers from the signed manifest', function () {
    putManifest($this->signer->sign(manifestClaims(['flags' => ['check-in' => true, 'kill-switch' => false]])));

    expect(Feature::for('__global__')->active('check-in'))->toBeTrue()
        ->and(Feature::for('__global__')->active('kill-switch'))->toBeFalse();
});

it('resolves a flag named with digits from the signed manifest', function () {
    $claims = json_encode(array_diff_key(manifestClaims(), ['flags' => true]), JSON_THROW_ON_ERROR);
    putManifest($this->signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', substr($claims, 0, -1).',"flags":{"123":true,"check-in":true}}'));

    expect(Feature::for('__global__')->active('123'))->toBeTrue()
        ->and(Feature::for('__global__')->active('check-in'))->toBeTrue()
        ->and(Feature::for('__global__')->active('456'))->toBeFalse();
});

it('gives every scope the global value of the manifest', function () {
    putManifest($this->signer->sign(manifestClaims(['flags' => ['check-in' => true]])));

    expect(Feature::for(new Person(['id' => 9]))->active('check-in'))->toBeTrue()
        ->and(Feature::for(null)->active('check-in'))->toBeTrue()
        ->and(Feature::for('anything')->active('check-in'))->toBeTrue();
});

it('falls back to the resolver of a flag the manifest does not mention, with the scope', function () {
    putManifest($this->signer->sign(manifestClaims(['flags' => ['check-in' => true]])));

    expect(Feature::for('x')->active('for-x'))->toBeTrue()
        ->and(Feature::for('y')->active('for-x'))->toBeFalse();
});

it('ignores a value written by hand in the features table', function () {
    Schema::create('features', function ($table): void {
        $table->string('name');
        $table->string('scope');
        $table->text('value');
    });
    DB::table('features')->insert(['name' => 'check-in', 'scope' => '__global__', 'value' => json_encode(true)]);
    Feature::flushCache();

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse();
});

it('falls back to the shipped defaults when the manifest cannot be trusted', function (Closure $token) {
    putManifest($token($this->signer));

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse()
        ->and(Feature::for('__global__')->active('kill-switch'))->toBeTrue();
})->with([
    'a changed payload' => [function (Signer $signer): string {
        [$header, , $signature] = explode('.', $signer->sign(manifestClaims(['flags' => []])));
        $forged = rtrim(strtr(base64_encode(json_encode(manifestClaims(['ver' => 99, 'flags' => ['check-in' => true, 'kill-switch' => false]]))), '+/', '-_'), '=');

        return $header.'.'.$forged.'.'.$signature;
    }],
    'another key' => [fn () => (new Signer('another-seed'))->sign(manifestClaims(['flags' => ['check-in' => true]]))],
    'another installation' => [fn (Signer $signer) => $signer->sign(manifestClaims(['aud' => 'someone-else', 'flags' => ['check-in' => true]]))],
    'another product' => [fn (Signer $signer) => $signer->sign(manifestClaims(['prd' => 'another-product', 'flags' => ['check-in' => true]]))],
    'flags as a string' => [fn (Signer $signer) => $signer->sign(manifestClaims(['flags' => 'all']))],
    'version as a string' => [fn (Signer $signer) => $signer->sign(manifestClaims(['ver' => '2', 'flags' => ['check-in' => true]]))],
    'a flag that is not a boolean' => [fn (Signer $signer) => $signer->sign(manifestClaims(['flags' => ['check-in' => 'yes']]))],
    'key id an array' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":[]}', json_encode(manifestClaims(['flags' => ['check-in' => true]])))],
    'a key id that is not configured' => [fn (Signer $signer) => $signer->sign(manifestClaims(['flags' => ['check-in' => true]]), 'mizuno-run-club-local')],
    'garbage' => [fn () => 'garbage'],
]);

it('keeps verifying a manifest signed with the old key while both keys are configured', function () {
    $old = new Signer('old-seed', 'key-old');
    $new = new Signer('new-seed', 'key-new');
    config()->set('platform-authorizer.keys', [
        $old->keyId() => $old->publicKey(),
        $new->keyId() => $new->publicKey(),
    ]);
    putManifest($old->sign(manifestClaims(['flags' => ['check-in' => true]])));

    expect(Feature::for('__global__')->active('check-in'))->toBeTrue();

    putManifest($new->sign(manifestClaims(['ver' => 5, 'flags' => ['check-in' => false, 'kill-switch' => false]])));

    expect(Feature::for('__global__')->active('kill-switch'))->toBeFalse();
});

it('falls back to the shipped defaults once the key that signed the manifest is removed', function () {
    $old = new Signer('old-seed', 'key-old');
    $new = new Signer('new-seed', 'key-new');
    config()->set('platform-authorizer.keys', [$old->keyId() => $old->publicKey(), $new->keyId() => $new->publicKey()]);
    putManifest($old->sign(manifestClaims(['flags' => ['check-in' => true]])));

    config()->set('platform-authorizer.keys', [$new->keyId() => $new->publicKey()]);
    Feature::flushCache();

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse();
});

it('falls back to the shipped defaults, and says so, when the table is missing', function () {
    Schema::drop('feature_manifests');
    Feature::flushCache();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse()
        ->and(implode("\n", $logged))->toContain('unreadable')->not->toContain('vendor@example.test');
});

it('falls back to the shipped defaults when the configuration cannot be used', function () {
    putManifest($this->signer->sign(manifestClaims(['flags' => ['check-in' => true]])));
    config()->set('platform-authorizer.keys', []);
    Log::spy();
    Feature::flushCache();

    expect(Feature::for('__global__')->active('check-in'))->toBeFalse();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context === ['setting' => 'keys']);
});

it('reads the manifest once per request, however many flags are asked for', function () {
    putManifest($this->signer->sign(manifestClaims(['flags' => ['check-in' => true]])));
    DB::enableQueryLog();

    Feature::for('__global__')->active('check-in');
    Feature::for('__global__')->active('kill-switch');
    Feature::for('x')->active('for-x');
    Feature::for('x')->active('check-in');

    $reads = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'feature_manifests'));

    expect($reads)->toHaveCount(1);
});

it('reports a missing manifest once per request, however many flags are asked for', function () {
    Feature::flushCache();
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message;
    });

    Feature::for('__global__')->active('check-in');
    Feature::for('__global__')->active('kill-switch');
    Feature::for('x')->active('check-in');

    expect($logged)->toBe(['Feature manifest missing, shipped defaults in use']);
});

it('turns a flag on through the authorizer, with a single call per toggle', function () {
    grantSession();
    authorizerSigns($this->signer);

    // What an application does to switch a flag on for everybody.
    Feature::activateForEveryone('check-in');
    Feature::for('__global__')->activate('check-in');

    Http::assertSentCount(1);
    expect(Feature::for('__global__')->active('check-in'))->toBeTrue()
        ->and(app(ManifestRepository::class)->current()?->version)->toBe(1);
});

it('keeps the other flags when one changes', function () {
    grantSession();
    authorizerSigns($this->signer);

    Feature::for('__global__')->activate('check-in');
    Feature::for('__global__')->deactivate('kill-switch');

    Http::assertSent(fn (Request $request): bool => $request->data()['flags'] === ['check-in' => true, 'kill-switch' => false]);
    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['check-in' => true, 'kill-switch' => false]);
});

it('keeps flags named with digits when others change or are forgotten', function () {
    grantSession();
    authorizerSigns($this->signer);

    Feature::for('__global__')->activate('123');
    Feature::for('__global__')->activate('456');
    Feature::for('__global__')->deactivate('check-in');

    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['123' => true, '456' => true, 'check-in' => false]);

    Feature::purge('123');

    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['456' => true, 'check-in' => false])
        ->and(Feature::for('__global__')->active('456'))->toBeTrue();
});

it('sends flags named only with sequential digits as an object', function () {
    grantSession();
    authorizerSigns($this->signer);

    Feature::for('__global__')->activate('0');
    Feature::for('__global__')->activate('1');

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"flags":{"0":true,"1":true}'));
    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['0' => true, '1' => true]);
});

it('turns a flag off through the authorizer', function () {
    grantSession();
    authorizerSigns($this->signer);
    Feature::for('__global__')->activate('check-in');

    Feature::deactivateForEveryone('check-in');
    Feature::for('__global__')->deactivate('check-in');

    Http::assertSentCount(2);
    expect(Feature::for('__global__')->active('check-in'))->toBeFalse();
});

it('does not call the authorizer when nothing changes', function () {
    grantSession();
    authorizerSigns($this->signer);
    Feature::for('__global__')->activate('check-in');

    Feature::for('__global__')->activate('check-in');
    Feature::activateForEveryone('check-in');

    Http::assertSentCount(1);
});

it('forgets flags through the authorizer', function () {
    grantSession();
    authorizerSigns($this->signer);
    Feature::for('__global__')->activate('check-in');
    Feature::for('__global__')->activate('kill-switch');

    Feature::purge('check-in');

    expect(app(ManifestRepository::class)->current()?->flags)->toBe(['kill-switch' => true]);

    Feature::purge();

    expect(app(ManifestRepository::class)->current()?->flags)->toBe([]);
    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"flags":{}'));
});

it('refuses to write a value for a scope that is not the global one', function () {
    grantSession();
    authorizerSigns($this->signer);

    expect(fn () => Feature::for(new Person(['id' => 9]))->activate('check-in'))->toThrow(UnsupportedScopeException::class)
        ->and(fn () => Feature::for('x')->deactivate('check-in'))->toThrow(UnsupportedScopeException::class)
        ->and(fn () => Feature::for(null)->forget('check-in'))->toThrow(UnsupportedScopeException::class);

    Http::assertNothingSent();
});

it('refuses to change a flag without an authorization in the session', function () {
    Http::fake();

    expect(fn () => Feature::for('__global__')->activate('check-in'))->toThrow(AuthorizationRejectedException::class);

    Http::assertNothingSent();
    expect(Feature::for('__global__')->active('check-in'))->toBeFalse();
});

it('answers 419 when the authorizer says the assertion has expired', function () {
    grantSession();
    Http::fake(['*' => Http::response(['error' => 'expired'], 401)]);

    try {
        Feature::for('__global__')->activate('check-in');
    } catch (AuthorizationExpiredException $exception) {
        expect($exception->getStatusCode())->toBe(419)
            ->and(Feature::for('__global__')->active('check-in'))->toBeFalse();

        return;
    }

    $this->fail('The write should have been refused.');
});

it('answers 403 to an expired flag write when the expired status is 403', function () {
    config()->set('platform-authorizer.expired_status', 403);
    grantSession();
    Http::fake(['*' => Http::response(['error' => 'expired'], 401)]);

    try {
        Feature::for('__global__')->activate('check-in');
    } catch (AuthorizationExpiredException $exception) {
        expect($exception->getStatusCode())->toBe(403)
            ->and($exception->getMessage())->toBe('The platform authorization has expired.');

        return;
    }

    $this->fail('The write should have been refused.');
});

it('registers the driver under the name the pennant configuration uses', function () {
    expect(config('pennant.stores.platform-authorizer.driver'))->toBe('platform-authorizer')
        ->and(Feature::store('platform-authorizer')->getDriver())->toBeInstanceOf(SignedFeatureDriver::class);
});
