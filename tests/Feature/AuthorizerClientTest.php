<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PlinCode\PlatformAuthorizer\Client\AuthorizerClient;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    $this->client = new AuthorizerClient(settingsFor(new Signer));
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

it('builds the authorize url from the configuration and a nonce', function () {
    expect($this->client->authorizeUrl(TEST_NONCE))
        ->toBe('https://auth.example.test/v1/authorize?product=mizuno-run-club&installation=mizuno-acme&nonce='.TEST_NONCE);
});

it('refuses a nonce that is not 64 lowercase hexadecimal characters', function (string $nonce) {
    expect(fn () => $this->client->authorizeUrl($nonce))->toThrow(InvalidArgumentException::class);
})->with(['short' => ['abc'], 'uppercase' => [strtoupper(TEST_NONCE)], 'not hex' => [str_repeat('z', 64)], 'empty' => ['']]);

it('fetches the latest manifest token', function () {
    Http::fake(['auth.example.test/v1/flags/mizuno-acme' => Http::response(['manifest' => 'a.b.c'])]);

    expect($this->client->fetchManifest())->toBe('a.b.c');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
        && $request->url() === 'https://auth.example.test/v1/flags/mizuno-acme');
});

it('reports that no manifest exists yet', function () {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    expect($this->client->fetchManifest())->toBeNull();
});

it('fails closed when the authorizer cannot be used to read the manifest', function (Closure $fake) {
    $fake();

    expect(fn () => $this->client->fetchManifest())->toThrow(AuthorizerUnavailableException::class);
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response('boom', 500)])],
    'rate limited' => [fn () => Http::fake(['*' => Http::response(['error' => 'x'], 429)])],
    'connection failure' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timed out')])],
    'no manifest in the body' => [fn () => Http::fake(['*' => Http::response(['other' => 'x'])])],
    'manifest of the wrong type' => [fn () => Http::fake(['*' => Http::response(['manifest' => ['x']])])],
    'body not json' => [fn () => Http::fake(['*' => Http::response('<html>')])],
]);

it('does not retry a failed call', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    expect(fn () => $this->client->writeFlags('the-assertion', ['check-in' => true]))->toThrow(AuthorizerUnavailableException::class);

    Http::assertSentCount(1);
});

it('writes flags with the assertion as bearer and returns the new manifest', function () {
    Http::fake(['*' => Http::response(['manifest' => 'new.manifest.token'])]);

    $token = $this->client->writeFlags('the-assertion', ['check-in' => true, 'other' => false]);

    expect($token)->toBe('new.manifest.token');

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->url() === 'https://auth.example.test/v1/flags'
        && $request->header('Authorization') === ['Bearer the-assertion']
        && $request->data() === ['installation' => 'mizuno-acme', 'flags' => ['check-in' => true, 'other' => false]]);
});

it('sends an empty flag set as an object, not as a list', function () {
    Http::fake(['*' => Http::response(['manifest' => 'a.b.c'])]);

    $this->client->writeFlags('the-assertion', []);

    Http::assertSent(fn (Request $request): bool => str_contains($request->body(), '"flags":{}'));
});

it('maps every refusal of a write to an exception with a meaningful status', function (int $status, array $body, string $exception, int $httpStatus) {
    Http::fake(['*' => Http::response($body, $status)]);

    try {
        $this->client->writeFlags('secret-assertion-token', ['check-in' => true]);
    } catch (Throwable $caught) {
        expect($caught)->toBeInstanceOf($exception)
            ->and($caught->getStatusCode())->toBe($httpStatus);

        return;
    }

    $this->fail('The write should have been refused.');
})->with([
    'expired assertion' => [401, ['error' => 'expired'], AuthorizationExpiredException::class, 419],
    'invalid assertion' => [401, ['error' => 'invalid'], AuthorizationRejectedException::class, 403],
    'identity not allowed' => [403, ['error' => 'forbidden'], AuthorizationRejectedException::class, 403],
    'malformed request' => [400, ['error' => 'malformed'], AuthorizerUnavailableException::class, 503],
    'rate limited' => [429, [], AuthorizerUnavailableException::class, 503],
    'server error' => [500, [], AuthorizerUnavailableException::class, 503],
    'unknown 401 body' => [401, ['error' => 'something'], AuthorizationRejectedException::class, 403],
]);

it('fails closed when the authorizer cannot be reached for a write', function () {
    Http::fake(['*' => fn () => throw new ConnectionException('timed out')]);

    expect(fn () => $this->client->writeFlags('secret-assertion-token', []))->toThrow(AuthorizerUnavailableException::class);
});

it('sends a tamper event and reports whether it was accepted', function () {
    Http::fake(['*' => Http::response('', 202)]);

    expect($this->client->reportTamper('enforce flag disabled'))->toBeTrue();

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://auth.example.test/v1/events'
        && $request->data() === [
            'installation' => 'mizuno-acme',
            'product' => 'mizuno-run-club',
            'type' => 'tamper',
            'reason' => 'enforce flag disabled',
        ]);
});

it('cuts the reason of an event to 200 characters', function () {
    Http::fake(['*' => Http::response('', 202)]);

    $this->client->reportTamper(str_repeat('é', 300));

    Http::assertSent(fn (Request $request): bool => mb_strlen($request->data()['reason']) === 200);
});

it('never raises when an event cannot be delivered', function (Closure $fake) {
    $fake();

    expect($this->client->reportTamper('x'))->toBeFalse();
})->with([
    'server error' => [fn () => Http::fake(['*' => Http::response('', 500)])],
    'rate limited' => [fn () => Http::fake(['*' => Http::response('', 429)])],
    'connection failure' => [fn () => Http::fake(['*' => fn () => throw new ConnectionException('timed out')])],
]);

/**
 * Fakes an authorizer that answers every call with a redirect to another
 * host, and that other host as a place that would accept anything.
 */
function fakeRedirectToAnotherHost(int $status = 302): void
{
    Http::fake([
        'auth.example.test/*' => Http::response('', $status, ['Location' => 'https://elsewhere.example.test/collect']),
        'elsewhere.example.test/*' => Http::response(['manifest' => 'a.b.c'], 200),
    ]);
}

function assertNothingWentElsewhere(): void
{
    Http::assertSentCount(1);
    Http::assertNotSent(fn (Request $request): bool => $request->toPsrRequest()->getUri()->getHost() === 'elsewhere.example.test');
}

it('never follows a redirect to another host with the bearer', function (int $status) {
    fakeRedirectToAnotherHost($status);

    expect(fn () => $this->client->writeFlags('the-assertion', ['check-in' => true]))
        ->toThrow(AuthorizerUnavailableException::class);

    assertNothingWentElsewhere();
})->with([301, 302, 303, 307, 308]);

it('does not follow a redirect when it reads the manifest', function () {
    fakeRedirectToAnotherHost();

    expect(fn () => $this->client->fetchManifest())->toThrow(AuthorizerUnavailableException::class);

    assertNothingWentElsewhere();
});

it('does not follow a redirect when it reports a tamper event, and never raises', function () {
    fakeRedirectToAnotherHost();

    expect($this->client->reportTamper('x'))->toBeFalse();

    assertNothingWentElsewhere();
});

it('keeps the assertion, the nonce and the email out of the logs', function () {
    Http::fake(['*' => Http::response(['error' => 'expired'], 401)]);

    try {
        $this->client->writeFlags('secret-assertion-token', ['check-in' => true]);
    } catch (Throwable) {
        // The refusal is expected, only the log matters here.
    }

    $this->client->authorizeUrl(TEST_NONCE);

    expect(implode("\n", $this->logged))
        ->not->toContain('secret-assertion-token')
        ->not->toContain(TEST_NONCE)
        ->not->toContain('vendor@example.test');
});
