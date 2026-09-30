<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PlinCode\PlatformAuthorizer\Http\LivewireRequest;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->user = new GenericUser(['id' => 1, 'email' => 'vendor@example.test']);
});

afterEach(fn () => Carbon::setTestNow());

/**
 * The session of a user holding an assertion that expired the given number
 * of seconds ago (negative: that will expire in the future).
 */
function holding(int $expiredSecondsAgo = -3600, array $claims = [], ?Signer $signer = null): array
{
    $token = ($signer ?? test()->signer)->sign(assertionClaims(['exp' => now()->getTimestamp() - $expiredSecondsAgo, ...$claims]));

    return [PlatformAuthorization::SESSION_ASSERTION => $token];
}

/**
 * Renders the probe page with a valid authorization and returns the
 * snapshot of its component, the way a browser holds it.
 */
function probeSnapshot(): string
{
    $page = test()->actingAs(test()->user)->withSession(holding())->get('/probe')->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
    expect($matches)->toHaveKey(1);

    return html_entity_decode($matches[1], ENT_QUOTES);
}

/**
 * Replays the snapshot through the real update endpoint, so the route
 * middleware and Livewire's persistent middleware run as in a browser.
 */
function livewireUpdate(string $snapshot, array $session): TestResponse
{
    return test()->actingAs(test()->user)
        ->withSession($session)
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => $snapshot, 'updates' => new stdClass, 'calls' => []]],
        ]);
}

it('serves a Livewire request while the authorization is valid', function () {
    livewireUpdate(probeSnapshot(), holding())->assertOk();
});

it('refuses a Livewire request that carries no authorization with a 403', function () {
    $snapshot = probeSnapshot();

    // Every request of a test shares one session: start the next one empty.
    $this->flushSession();

    livewireUpdate($snapshot, [])->assertForbidden();
});

it('refuses a Livewire request whose authorization is not valid with a 403, and forgets it', function (array $claims, ?Signer $signer) {
    $snapshot = probeSnapshot();

    livewireUpdate($snapshot, holding(-3600, $claims, $signer))
        ->assertForbidden();

    $this->assertNull(app(PlatformAuthorization::class)->assertion());
})->with([
    'signature' => [[], new Signer('another-seed')],
    'audience' => [['aud' => 'someone-else'], null],
    'product' => [['prd' => 'another-product'], null],
    'issuer' => [['iss' => 'https://evil.example.test'], null],
    'email' => [['email' => 'someone@example.test'], null],
]);

it('serves a Livewire request for 900 seconds after the expiry', function (int $secondsAgo) {
    livewireUpdate(probeSnapshot(), holding($secondsAgo))->assertOk();
})->with([[60], [600], [900]]);

it('answers a Livewire request 419 once the grace is over, so the page reloads', function (int $secondsAgo) {
    livewireUpdate(probeSnapshot(), holding($secondsAgo))->assertStatus(419);
})->with([[901], [3600]]);

it('answers a Livewire request 403 once the grace is over when the expired status is 403', function () {
    config()->set('platform-authorizer.expired_status', 403);

    livewireUpdate(probeSnapshot(), holding(901))->assertForbidden();
});

it('keeps serving a Livewire request inside the grace when the expired status is 403', function () {
    config()->set('platform-authorizer.expired_status', 403);

    livewireUpdate(probeSnapshot(), holding(600))->assertOk();
});

it('gives no grace to a navigation, even one that claims to be Livewire', function () {
    $this->actingAs($this->user)
        ->withSession(holding(600))
        ->withHeaders(['X-Livewire' => '1'])
        ->get('/probe')
        ->assertRedirect(route('platform-authorizer.redirect'));
});

it('gives no grace to a POST that is not aimed at the update endpoint either', function () {
    $this->actingAs($this->user)
        ->withSession(holding(600))
        ->withHeaders(['X-Livewire' => '1'])
        ->post('/probe/logout')
        ->assertRedirect(route('platform-authorizer.redirect'));
});

it('recognises a POST to the update endpoint and nothing else as a Livewire update', function (string $method, string $path, bool $expected) {
    $path = $path === 'update' ? app(HandleRequests::class)->getUpdateUri() : $path;
    $this->app->instance('request', Request::create($path, $method, server: ['HTTP_X_LIVEWIRE' => '1']));

    expect(LivewireRequest::isUpdate())->toBe($expected);
})->with([
    'a POST to the endpoint' => ['POST', 'update', true],
    'a GET to the endpoint' => ['GET', 'update', false],
    'a POST to a page' => ['POST', '/probe', false],
    'a GET to a page' => ['GET', '/probe', false],
]);
