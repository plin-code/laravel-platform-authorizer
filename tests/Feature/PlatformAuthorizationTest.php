<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Assertions\VerificationStatus;
use PlinCode\PlatformAuthorizer\Facades\PlatformAuthorization as PlatformAuthorizationFacade;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->startSession();
    $this->authorization = app(PlatformAuthorization::class);
});

afterEach(fn () => Carbon::setTestNow());

function vendorUser(string $email = 'vendor@example.test'): GenericUser
{
    return new GenericUser(['id' => 1, 'email' => $email]);
}

it('holds nothing before an authorization is granted', function () {
    $this->actingAs(vendorUser());

    expect($this->authorization->assertion())->toBeNull()
        ->and($this->authorization->check())->toBeNull()
        ->and($this->authorization->isGranted())->toBeFalse();
});

it('is granted while the stored assertion is valid for the logged in user', function () {
    $this->actingAs(vendorUser());
    $this->authorization->grant($this->signer->sign(assertionClaims()));

    expect($this->authorization->isGranted())->toBeTrue()
        ->and($this->authorization->check()?->status)->toBe(VerificationStatus::Valid)
        ->and($this->authorization->assertion())->toBeString();
});

it('can be called statically through the facade', function () {
    $this->actingAs(vendorUser());
    $this->authorization->grant($this->signer->sign(assertionClaims()));

    expect(PlatformAuthorizationFacade::isGranted())->toBeTrue();
});

it('is not granted to another user, to nobody, or after the expiry', function (?string $email, int $exp, VerificationStatus $status) {
    if ($email !== null) {
        $this->actingAs(vendorUser($email));
    }

    $this->authorization->grant($this->signer->sign(assertionClaims(['exp' => now()->getTimestamp() + $exp])));

    expect($this->authorization->isGranted())->toBeFalse()
        ->and($this->authorization->check()?->status)->toBe($status);
})->with([
    'another user' => ['someone@example.test', 3600, VerificationStatus::Invalid],
    'no user' => [null, 3600, VerificationStatus::Invalid],
    'expired' => ['vendor@example.test', -3600, VerificationStatus::Expired],
]);

it('forgets the assertion', function () {
    $this->actingAs(vendorUser());
    $this->authorization->grant($this->signer->sign(assertionClaims()));

    $this->authorization->forget();

    expect($this->authorization->assertion())->toBeNull()
        ->and($this->authorization->isGranted())->toBeFalse();
});

it('reads the email of a user that is not a password reset contract', function () {
    $this->actingAs(new GenericUser(['id' => 2, 'email' => 'VENDOR@example.test']));
    $this->authorization->grant($this->signer->sign(assertionClaims()));

    expect($this->authorization->isGranted())->toBeTrue();
});

it('treats a stored value that is not a string as no assertion', function (mixed $value) {
    $this->actingAs(vendorUser());
    session()->put(PlatformAuthorization::SESSION_ASSERTION, $value);

    expect($this->authorization->assertion())->toBeNull()
        ->and($this->authorization->isGranted())->toBeFalse();
})->with([[['a']], [12], [true], ['']]);

it('is not granted when the stored string is not a genuine assertion', function () {
    $this->actingAs(vendorUser());
    session()->put(PlatformAuthorization::SESSION_ASSERTION, 'hand.written.value');

    expect($this->authorization->check()?->status)->toBe(VerificationStatus::Invalid)
        ->and($this->authorization->isGranted())->toBeFalse();
});

it('issues a fresh nonce that can be consumed only once', function () {
    $first = $this->authorization->issueNonce();
    $second = $this->authorization->issueNonce();

    expect($first)->toMatch('/^[0-9a-f]{64}$/')
        ->and($second)->not->toBe($first)
        ->and($this->authorization->pullNonce())->toBe($second)
        ->and($this->authorization->pullNonce())->toBeNull();
});

it('fails closed, and says why, when the configuration is unusable', function () {
    config()->set('platform-authorizer.keys', []);
    $this->actingAs(vendorUser());
    session()->put(PlatformAuthorization::SESSION_ASSERTION, 'a.b.c');

    Log::spy();

    expect($this->authorization->isGranted())->toBeFalse()
        ->and($this->authorization->check()?->reason)->toBe('configuration');

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context): bool => $context === ['setting' => 'keys'],
    );
});

it('is not granted while the session has not been started', function () {
    $this->actingAs(vendorUser());
    $this->authorization->grant($this->signer->sign(assertionClaims()));

    $this->app->instance('session.store', new Store('idle', new ArraySessionHandler(120)));

    expect($this->authorization->isGranted())->toBeFalse()
        ->and($this->authorization->assertion())->toBeNull();
});

it('accepts a fresh assertion for the logged in user and keeps it in the session', function () {
    $this->actingAs(vendorUser());

    $result = $this->authorization->accept($this->signer->sign(assertionClaims()), TEST_NONCE);

    expect($result->isValid())->toBeTrue()
        ->and($this->authorization->isGranted())->toBeTrue();
});

it('does not keep an assertion that fails the checks of a fresh one', function (string $nonce, array $claims, string $reason) {
    $this->actingAs(vendorUser());

    $result = $this->authorization->accept($this->signer->sign(assertionClaims($claims)), $nonce);

    expect($result->isValid())->toBeFalse()
        ->and($result->reason)->toBe($reason)
        ->and($this->authorization->assertion())->toBeNull();
})->with([
    'other nonce' => [str_repeat('0', 64), [], 'wrong_nonce'],
    'other user' => [TEST_NONCE, ['email' => 'someone@example.test'], 'email_mismatch'],
    'expired' => [TEST_NONCE, ['exp' => 1_789_990_000], 'expired'],
]);
