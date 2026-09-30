<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer, ['except_routes' => ['probe.logout']]));
    $this->user = new GenericUser(['id' => 1, 'email' => 'vendor@example.test']);
});

afterEach(fn () => Carbon::setTestNow());

function session_with(array $claims = [], ?Signer $signer = null): array
{
    return [PlatformAuthorization::SESSION_ASSERTION => ($signer ?? test()->signer)->sign(assertionClaims($claims))];
}

it('opens the page with a valid authorization', function () {
    $this->actingAs($this->user)->withSession(session_with())->get('/probe')->assertOk()->assertSee('count: 0');
});

it('sends a navigation without an authorization through the authorizer and remembers where it was going', function () {
    $this->actingAs($this->user)
        ->get('/probe')
        ->assertRedirect(route('platform-authorizer.redirect'))
        ->assertSessionHas('url.intended', url('/probe'));
});

it('sends an expired navigation through the authorizer again', function () {
    $this->actingAs($this->user)
        ->withSession(session_with(['exp' => now()->getTimestamp() - 3600]))
        ->get('/probe')
        ->assertRedirect(route('platform-authorizer.redirect'));
});

it('refuses a navigation whose authorization is not valid, and forgets it', function (array $claims, ?Signer $signer) {
    $this->actingAs($this->user)
        ->withSession(session_with($claims, $signer))
        ->get('/probe')
        ->assertForbidden()
        ->assertSessionMissing(PlatformAuthorization::SESSION_ASSERTION);
})->with([
    'signed by another key' => [[], new Signer('another-seed')],
    'unknown key id' => [[], new Signer('another-seed', 'mizuno-run-club-local')],
    'another installation' => [['aud' => 'someone-else'], null],
    'another product' => [['prd' => 'another-product'], null],
    'another issuer' => [['iss' => 'https://evil.example.test'], null],
    'another user' => [['email' => 'someone@example.test'], null],
]);

it('refuses a session row that holds an assertion written by hand', function () {
    $this->actingAs($this->user)
        ->withSession([PlatformAuthorization::SESSION_ASSERTION => 'hand.written.token'])
        ->get('/probe')
        ->assertForbidden();
});

it('refuses an assertion issued for someone else when nobody is logged in', function () {
    $this->withSession(session_with())->get('/probe')->assertForbidden();
});

it('lets the excepted routes through without any authorization', function () {
    $this->actingAs($this->user)->post('/probe/logout')->assertOk()->assertSee('logged out');
});

it('refuses when the expired status is not one of the two allowed', function () {
    config()->set('platform-authorizer.expired_status', 500);

    $this->actingAs($this->user)->withSession(session_with())->get('/probe')->assertForbidden();
});

it('refuses when the configuration cannot be used', function () {
    config()->set('platform-authorizer.keys', []);

    $this->actingAs($this->user)->withSession(session_with())->get('/probe')->assertForbidden();
});
