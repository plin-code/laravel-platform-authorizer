<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use PlinCode\PlatformAuthorizer\Http\AccessDecider;
use PlinCode\PlatformAuthorizer\Http\AccessDecision;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->startSession();
    $this->actingAs(new GenericUser(['id' => 1, 'email' => 'vendor@example.test']));
    $this->authorization = app(PlatformAuthorization::class);
    $this->decider = app(AccessDecider::class);
});

afterEach(fn () => Carbon::setTestNow());

function grantWith(array $claims = [], ?Signer $signer = null): void
{
    app(PlatformAuthorization::class)->grant(($signer ?? test()->signer)->sign(assertionClaims($claims)));
}

it('sends a navigation without any authorization to the authorizer, and refuses a Livewire request', function () {
    expect($this->decider->decide(livewireUpdate: false))->toBe(AccessDecision::Authorize)
        ->and($this->decider->decide(livewireUpdate: true))->toBe(AccessDecision::Forbidden);
});

it('lets a valid authorization through everywhere', function (bool $livewire) {
    grantWith();

    expect($this->decider->decide($livewire))->toBe(AccessDecision::Allow);
})->with([[false], [true]]);

it('refuses an authorization that is present but not valid, and forgets it', function (array $claims, ?Signer $signer, bool $livewire) {
    grantWith($claims, $signer);

    expect($this->decider->decide($livewire))->toBe(AccessDecision::Forbidden)
        ->and($this->authorization->assertion())->toBeNull();
})->with([
    'signature' => [[], new Signer('another-seed'), false],
    'signature on Livewire' => [[], new Signer('another-seed'), true],
    'audience' => [['aud' => 'someone-else'], null, false],
    'audience on Livewire' => [['aud' => 'someone-else'], null, true],
    'product' => [['prd' => 'another-product'], null, false],
    'issuer' => [['iss' => 'https://evil.example.test'], null, false],
    'email' => [['email' => 'someone@example.test'], null, false],
    'email on Livewire' => [['email' => 'someone@example.test'], null, true],
    'expired and meant for someone else' => [['email' => 'someone@example.test', 'exp' => 1_789_990_000], null, true],
]);

it('refuses when there is no logged in user', function (bool $livewire) {
    grantWith();
    auth()->forgetGuards();
    $this->app['auth']->guard()->logout();

    expect($this->decider->decide($livewire))->toBe(AccessDecision::Forbidden);
})->with([[false], [true]]);

it('sends an expired navigation to the authorizer and keeps the assertion', function () {
    grantWith(['exp' => now()->getTimestamp() - 3600]);

    expect($this->decider->decide(livewireUpdate: false))->toBe(AccessDecision::Authorize)
        ->and($this->authorization->assertion())->not->toBeNull();
});

it('serves a Livewire request for 900 seconds after the expiry and answers 419 after that', function (int $secondsAgo, AccessDecision $decision) {
    grantWith(['exp' => now()->getTimestamp() - $secondsAgo]);

    expect($this->decider->decide(livewireUpdate: true))->toBe($decision);
})->with([
    'within the leeway' => [10, AccessDecision::Allow],
    'a minute after' => [60, AccessDecision::Allow],
    'at the end of the grace' => [900, AccessDecision::Allow],
    'a second past the grace' => [901, AccessDecision::Expired],
    'an hour after' => [3600, AccessDecision::Expired],
]);

it('follows the grace configured, and has none when it is zero', function (int $grace, int $secondsAgo, AccessDecision $decision) {
    config()->set('platform-authorizer.livewire_grace_seconds', $grace);
    grantWith(['exp' => now()->getTimestamp() - $secondsAgo]);

    expect($this->decider->decide(livewireUpdate: true))->toBe($decision);
})->with([
    'no grace, just expired' => [0, 31, AccessDecision::Expired],
    'no grace, still in the leeway' => [0, 30, AccessDecision::Allow],
    'short grace, inside' => [120, 100, AccessDecision::Allow],
    'short grace, outside' => [120, 121, AccessDecision::Expired],
]);

it('refuses, and keeps the assertion, when the configuration cannot be used', function () {
    grantWith();
    config()->set('platform-authorizer.keys', []);

    expect($this->decider->decide(livewireUpdate: false))->toBe(AccessDecision::Forbidden)
        ->and($this->authorization->assertion())->not->toBeNull();
});
