<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\Manifests\FlagWriter;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->startSession();
    $this->actingAs(new GenericUser(['id' => 1, 'email' => 'vendor@example.test']));
    app(PlatformAuthorization::class)->grant($this->signer->sign(assertionClaims()));
    $this->writer = app(FlagWriter::class);
});

function storedVersion(): ?int
{
    return app(ManifestRepository::class)->current()?->version;
}

it('asks the authorizer to sign the flags and keeps the manifest it returns', function () {
    Http::fake(['*' => Http::response(['manifest' => $this->signer->sign(manifestClaims(['ver' => 7, 'flags' => ['check-in' => true]]))])]);

    $this->writer->write(['check-in' => true]);

    expect(storedVersion())->toBe(7)
        ->and(app(ManifestRepository::class)->current()?->flags)->toBe(['check-in' => true]);

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.test/v1/flags'
        && $request->header('Authorization') === ['Bearer '.app(PlatformAuthorization::class)->assertion()]
        && $request->data() === ['installation' => 'mizuno-acme', 'flags' => ['check-in' => true]]);
});

it('does not call the authorizer without an authorization in the session', function () {
    app(PlatformAuthorization::class)->forget();
    Http::fake();

    expect(fn () => $this->writer->write(['check-in' => true]))->toThrow(AuthorizationRejectedException::class);

    Http::assertNothingSent();
    expect(DB::table('feature_manifests')->count())->toBe(0);
});

it('lets the refusals of the authorizer through and stores nothing', function (int $status, array $body, string $exception) {
    Http::fake(['*' => Http::response($body, $status)]);

    expect(fn () => $this->writer->write(['check-in' => true]))->toThrow($exception);
    expect(DB::table('feature_manifests')->count())->toBe(0);
})->with([
    'the assertion expired' => [401, ['error' => 'expired'], AuthorizationExpiredException::class],
    'the identity is not allowed' => [403, ['error' => 'forbidden'], AuthorizationRejectedException::class],
    'the authorizer is down' => [500, [], AuthorizerUnavailableException::class],
]);

it('refuses a manifest in the answer that it cannot trust and stores nothing', function (Closure $manifest) {
    Http::fake(['*' => fn () => Http::response(['manifest' => $manifest($this->signer)])]);

    expect(fn () => $this->writer->write(['check-in' => true]))->toThrow(AuthorizerUnavailableException::class);
    expect(DB::table('feature_manifests')->count())->toBe(0);
})->with([
    'signed by another key' => [fn () => (new Signer('another-seed'))->sign(manifestClaims())],
    'meant for another installation' => [fn (Signer $signer) => $signer->sign(manifestClaims(['aud' => 'someone-else']))],
    'not a manifest' => [fn () => 'garbage'],
]);

it('refuses a manifest older than the one already stored', function () {
    Http::fake(['*' => Http::sequence()
        ->push(['manifest' => $this->signer->sign(manifestClaims(['ver' => 5]))])
        ->push(['manifest' => $this->signer->sign(manifestClaims(['ver' => 4]))]),
    ]);

    $this->writer->write(['check-in' => true]);

    expect(fn () => $this->writer->write(['check-in' => false]))->toThrow(AuthorizerUnavailableException::class)
        ->and(storedVersion())->toBe(5);
});
