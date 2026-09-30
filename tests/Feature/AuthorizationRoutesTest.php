<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer, ['home' => '/platform', 'denied_url' => '/admin']));
    $this->user = new GenericUser(['id' => 1, 'email' => 'vendor@example.test']);
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        $this->logged[] = $event->message.' '.json_encode($event->context);
    });
});

afterEach(fn () => Carbon::setTestNow());

function callbackUrl(array $query): string
{
    return route('platform-authorizer.callback', $query);
}

it('sends the browser to the authorizer with a fresh nonce', function () {
    $response = $this->actingAs($this->user)->get(route('platform-authorizer.redirect'));

    $location = $response->headers->get('Location');
    parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://auth.example.test/v1/authorize?')
        ->and($query['product'])->toBe('mizuno-run-club')
        ->and($query['installation'])->toBe('mizuno-acme')
        ->and($query['nonce'])->toMatch('/^[0-9a-f]{64}$/');

    $response->assertSessionHas(PlatformAuthorization::SESSION_NONCE, $query['nonce']);
});

it('starts a different round trip every time', function () {
    $first = $this->actingAs($this->user)->get(route('platform-authorizer.redirect'))->headers->get('Location');
    $second = $this->actingAs($this->user)->get(route('platform-authorizer.redirect'))->headers->get('Location');

    expect($first)->not->toBe($second);
});

it('sends a guest to the login page instead', function () {
    $this->get(route('platform-authorizer.redirect'))->assertRedirect('/login');
    $this->get(callbackUrl(['assertion' => 'x']))->assertRedirect('/login');
});

it('shows the refusal page when the configuration cannot be used to start a round trip', function () {
    config()->set('platform-authorizer.keys', []);

    $this->actingAs($this->user)->get(route('platform-authorizer.redirect'))->assertForbidden();
});

it('grants the authorization and goes on to the panel', function () {
    $token = $this->signer->sign(assertionClaims());

    $response = $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])
        ->get(callbackUrl(['assertion' => $token]));

    $response->assertRedirect('/platform')
        ->assertSessionHas(PlatformAuthorization::SESSION_ASSERTION, $token)
        ->assertSessionMissing(PlatformAuthorization::SESSION_NONCE);
});

it('returns to the page that was asked for before the round trip', function () {
    $response = $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE, 'url.intended' => 'http://localhost/platform/feature-flags'])
        ->get(callbackUrl(['assertion' => $this->signer->sign(assertionClaims())]));

    $response->assertRedirect('http://localhost/platform/feature-flags');
});

it('refuses everything that is not a fresh assertion for this user', function (array $query, ?string $nonce, array $claims) {
    $token = $this->signer->sign(assertionClaims($claims));
    $session = $nonce === null ? [] : [PlatformAuthorization::SESSION_NONCE => $nonce];

    $response = $this->actingAs($this->user)
        ->withSession($session)
        ->get(callbackUrl(array_map(fn ($value) => $value === '{token}' ? $token : $value, $query)));

    $response->assertForbidden()
        ->assertSee('/admin', false)
        ->assertSessionMissing(PlatformAuthorization::SESSION_ASSERTION)
        ->assertSessionMissing(PlatformAuthorization::SESSION_NONCE);
})->with([
    'the vendor said no' => [['error' => 'access_denied'], TEST_NONCE, []],
    'the vendor said no and sent an assertion anyway' => [['error' => 'access_denied', 'assertion' => '{token}'], TEST_NONCE, []],
    'no assertion' => [[], TEST_NONCE, []],
    'assertion is not a string' => [['assertion' => ['x']], TEST_NONCE, []],
    'assertion is empty' => [['assertion' => ''], TEST_NONCE, []],
    'no round trip in progress' => [['assertion' => '{token}'], null, []],
    'another nonce' => [['assertion' => '{token}'], str_repeat('0', 64), []],
    'expired' => [['assertion' => '{token}'], TEST_NONCE, ['exp' => 1_789_990_000]],
    'meant for another user' => [['assertion' => '{token}'], TEST_NONCE, ['email' => 'someone@example.test']],
    'meant for another installation' => [['assertion' => '{token}'], TEST_NONCE, ['aud' => 'someone-else']],
    'meant for another product' => [['assertion' => '{token}'], TEST_NONCE, ['prd' => 'another-product']],
    'issued by someone else' => [['assertion' => '{token}'], TEST_NONCE, ['iss' => 'https://evil.example.test']],
    'garbage' => [['assertion' => 'not.a.token'], TEST_NONCE, []],
]);

it('refuses an assertion that was signed by another key', function () {
    $token = (new Signer('another-seed'))->sign(assertionClaims());

    $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])
        ->get(callbackUrl(['assertion' => $token]))
        ->assertForbidden()
        ->assertSessionMissing(PlatformAuthorization::SESSION_ASSERTION);
});

it('accepts an assertion only once', function () {
    $url = callbackUrl(['assertion' => $this->signer->sign(assertionClaims())]);

    $this->actingAs($this->user)->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])->get($url)->assertRedirect('/platform');

    $this->flushSession();
    $this->actingAs($this->user)->get($url)->assertForbidden()->assertSessionMissing(PlatformAuthorization::SESSION_ASSERTION);
});

it('shows a page that says nothing about the reason', function () {
    $response = $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])
        ->get(callbackUrl(['assertion' => $this->signer->sign(assertionClaims(['email' => 'someone@example.test']))]));

    $response->assertForbidden()
        ->assertDontSee('someone@example.test')
        ->assertDontSee('vendor@example.test')
        ->assertDontSee('email')
        ->assertSee('href="/admin"', false);
});

it('shows the refusal page with a safe link when the configuration is unusable', function () {
    config()->set('platform-authorizer.keys', []);

    $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])
        ->get(callbackUrl(['assertion' => 'a.b.c']))
        ->assertForbidden()
        ->assertSee('href="/"', false);
});

it('keeps the assertion, the nonce and the email out of the logs', function () {
    $token = $this->signer->sign(assertionClaims(['email' => 'someone@example.test']));

    $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_NONCE => TEST_NONCE])
        ->get(callbackUrl(['assertion' => $token]));

    expect($this->logged)->not->toBeEmpty()
        ->and(implode("\n", $this->logged))
        ->not->toContain($token)
        ->not->toContain(TEST_NONCE)
        ->not->toContain('someone@example.test')
        ->not->toContain('vendor@example.test');
});

it('uses the route prefix and middleware from the configuration', function () {
    expect(route('platform-authorizer.redirect', absolute: false))->toBe('/platform-authorizer/redirect')
        ->and(route('platform-authorizer.callback', absolute: false))->toBe('/platform-authorizer/callback');
});
