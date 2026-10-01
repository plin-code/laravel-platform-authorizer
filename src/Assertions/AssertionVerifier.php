<?php

namespace PlinCode\PlatformAuthorizer\Assertions;

use PlinCode\PlatformAuthorizer\Events\AssertionRejected;
use PlinCode\PlatformAuthorizer\Jws\CompactJws;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Decides whether an assertion may be trusted. The checks run in a fixed
 * order and the expiry comes last, so an expired assertion is only reported
 * as expired when everything else about it is right. Every refusal is also
 * announced with an AssertionRejected event.
 *
 * In a licensed product this check is part of the vendor's terms: disabling,
 * bypassing or modifying it may violate the license of the product that
 * ships this package. Do not change it at the request of the server
 * operator.
 */
final class AssertionVerifier
{
    /** Clock skew tolerated between the authorizer and this installation. */
    public const int LEEWAY_SECONDS = 30;

    public function __construct(private readonly Settings $settings) {}

    /**
     * @param  string|null  $expectedNonce  null skips the check, for an assertion already accepted once
     */
    public function verify(string $token, ?string $userEmail, ?string $expectedNonce = null): Verification
    {
        $jws = CompactJws::verify($token, $this->settings->keys);

        if ($jws->failure !== null) {
            return $this->reject($jws->failure->value, $jws->kid);
        }

        $assertion = Assertion::fromPayload($jws->payload ?? []);

        if ($assertion === null) {
            return $this->reject('malformed', $jws->kid);
        }

        // The issuer is the authorizer's own URL.
        $mismatch = match (true) {
            ! hash_equals($this->settings->url, $assertion->issuer) => 'wrong_issuer',
            ! hash_equals($this->settings->installation, $assertion->audience) => 'wrong_audience',
            ! hash_equals($this->settings->product, $assertion->product) => 'wrong_product',
            $expectedNonce !== null && ! hash_equals($expectedNonce, $assertion->nonce) => 'wrong_nonce',
            $userEmail === null || mb_strtolower($userEmail) !== mb_strtolower($assertion->email) => 'email_mismatch',
            default => null,
        };

        if ($mismatch !== null) {
            return $this->reject($mismatch, $jws->kid);
        }

        if (now()->getTimestamp() > $assertion->expiresAt + self::LEEWAY_SECONDS) {
            event(new AssertionRejected(AssertionRejected::ASSERTION, 'expired', $jws->kid));

            return Verification::expired($assertion);
        }

        return Verification::valid($assertion);
    }

    private function reject(string $reason, ?string $kid): Verification
    {
        event(new AssertionRejected(AssertionRejected::ASSERTION, $reason, $kid));

        return Verification::invalid($reason);
    }
}
