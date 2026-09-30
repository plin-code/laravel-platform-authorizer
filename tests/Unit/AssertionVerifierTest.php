<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use PlinCode\PlatformAuthorizer\Assertions\AssertionVerifier;
use PlinCode\PlatformAuthorizer\Assertions\VerificationStatus;
use PlinCode\PlatformAuthorizer\Events\AssertionRejected;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    $this->verifier = new AssertionVerifier(settingsFor($this->signer));
});

afterEach(fn () => Carbon::setTestNow());

it('accepts a valid assertion and exposes its claims', function () {
    $token = $this->signer->sign(assertionClaims());

    $result = $this->verifier->verify($token, 'vendor@example.test', TEST_NONCE);

    expect($result->status)->toBe(VerificationStatus::Valid)
        ->and($result->isValid())->toBeTrue()
        ->and($result->assertion?->subject)->toBe('github:1001')
        ->and($result->assertion?->email)->toBe('vendor@example.test')
        ->and($result->assertion?->expiresAt)->toBe(now()->getTimestamp() + 3600);
});

it('compares the email without regard to case', function () {
    $token = $this->signer->sign(assertionClaims(['email' => 'Vendor@Example.Test']));

    expect($this->verifier->verify($token, 'vendor@EXAMPLE.test')->isValid())->toBeTrue();
});

it('skips the nonce check when none is expected', function () {
    $token = $this->signer->sign(assertionClaims());

    expect($this->verifier->verify($token, 'vendor@example.test')->isValid())->toBeTrue();
});

it('refuses an assertion that does not match what was expected', function (array $claims, ?string $email, ?string $nonce, string $reason) {
    $result = $this->verifier->verify($this->signer->sign(assertionClaims($claims)), $email, $nonce);

    expect($result->status)->toBe(VerificationStatus::Invalid)
        ->and($result->reason)->toBe($reason);
})->with([
    'other issuer' => [['iss' => 'https://evil.example.test'], 'vendor@example.test', null, 'wrong_issuer'],
    'other installation' => [['aud' => 'someone-else'], 'vendor@example.test', null, 'wrong_audience'],
    'other product' => [['prd' => 'another-product'], 'vendor@example.test', null, 'wrong_product'],
    'other nonce' => [[], 'vendor@example.test', str_repeat('0', 64), 'wrong_nonce'],
    'other email' => [['email' => 'someone@example.test'], 'vendor@example.test', null, 'email_mismatch'],
    'no authenticated user' => [[], null, null, 'email_mismatch'],
]);

it('checks the expiry last, so an expired assertion for someone else is invalid', function () {
    $token = $this->signer->sign(assertionClaims(['exp' => now()->getTimestamp() - 3600, 'email' => 'someone@example.test']));

    expect($this->verifier->verify($token, 'vendor@example.test')->status)->toBe(VerificationStatus::Invalid);
});

it('reports an expired assertion as expired, with a 30 second leeway', function (int $secondsAgo, VerificationStatus $status) {
    $token = $this->signer->sign(assertionClaims(['exp' => now()->getTimestamp() - $secondsAgo]));

    $result = $this->verifier->verify($token, 'vendor@example.test');

    expect($result->status)->toBe($status);

    if ($status === VerificationStatus::Expired) {
        expect($result->assertion?->expiresAt)->toBe(now()->getTimestamp() - $secondsAgo);
    }
})->with([
    'expires in the future' => [-60, VerificationStatus::Valid],
    'expired just now' => [0, VerificationStatus::Valid],
    'expired 30 seconds ago' => [30, VerificationStatus::Valid],
    'expired 31 seconds ago' => [31, VerificationStatus::Expired],
    'expired an hour ago' => [3600, VerificationStatus::Expired],
]);

it('refuses tokens that are not genuine, and says why', function (Closure $build, string $reason) {
    $result = $this->verifier->verify($build($this->signer), 'vendor@example.test');

    expect($result->status)->toBe(VerificationStatus::Invalid)
        ->and($result->reason)->toBe($reason);
})->with([
    'garbage' => [fn () => 'not-a-token', 'malformed'],
    'unknown key id' => [fn (Signer $signer) => $signer->sign(assertionClaims(), 'unknown-key'), 'unknown_kid'],
    'another key under the same key id' => [fn () => (new Signer('another-seed'))->sign(assertionClaims()), 'bad_signature'],
    'key id an array' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":["test-key-1"]}', json_encode(assertionClaims())), 'malformed'],
]);

it('refuses a genuine token whose claims have the wrong type', function (array $claims) {
    $result = $this->verifier->verify($this->signer->sign(assertionClaims($claims)), 'vendor@example.test');

    expect($result->status)->toBe(VerificationStatus::Invalid)
        ->and($result->reason)->toBe('malformed');
})->with([
    'expiry as a string' => [['exp' => '9999999999']],
    'expiry as a float' => [['exp' => 1_790_003_600.5]],
    'expiry as an array' => [['exp' => [1]]],
    'expiry missing' => [['exp' => null]],
    'issued at as a string' => [['iat' => 'now']],
    'email as an array' => [['email' => ['vendor@example.test']]],
    'subject as a number' => [['sub' => 1001]],
    'audience as an array' => [['aud' => ['mizuno-acme']]],
    'product missing' => [['prd' => null]],
    'nonce as a number' => [['nonce' => 12]],
    'identifier empty' => [['jti' => '']],
]);

it('announces every refusal with the reason and the declared key id only', function (Closure $build, string $reason, ?string $kid) {
    Event::fake([AssertionRejected::class]);

    $this->verifier->verify($build($this->signer), 'vendor@example.test');

    Event::assertDispatchedTimes(AssertionRejected::class, 1);
    Event::assertDispatched(AssertionRejected::class, function (AssertionRejected $event) use ($reason, $kid): bool {
        expect($event->subject)->toBe('assertion')
            ->and($event->reason)->toBe($reason)
            ->and($event->kid)->toBe($kid)
            ->and(array_keys(get_object_vars($event)))->toBe(['subject', 'reason', 'kid']);

        return true;
    });
})->with([
    'unknown key id' => [fn (Signer $signer) => $signer->sign(assertionClaims(), 'mizuno-run-club-local'), 'unknown_kid', 'mizuno-run-club-local'],
    'bad signature' => [fn () => (new Signer('another-seed'))->sign(assertionClaims()), 'bad_signature', 'test-key-1'],
    'key id not a string' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":["x"]}', '{}'), 'malformed', null],
    'wrong audience' => [fn (Signer $signer) => $signer->sign(assertionClaims(['aud' => 'someone-else'])), 'wrong_audience', 'test-key-1'],
    'other email' => [fn (Signer $signer) => $signer->sign(assertionClaims(['email' => 'someone@example.test'])), 'email_mismatch', 'test-key-1'],
    'expired' => [fn (Signer $signer) => $signer->sign(assertionClaims(['exp' => now()->getTimestamp() - 3600])), 'expired', 'test-key-1'],
]);

it('does not announce anything for a valid assertion', function () {
    Event::fake([AssertionRejected::class]);

    $this->verifier->verify($this->signer->sign(assertionClaims()), 'vendor@example.test');

    Event::assertNotDispatched(AssertionRejected::class);
});
