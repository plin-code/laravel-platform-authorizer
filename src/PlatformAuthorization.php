<?php

namespace PlinCode\PlatformAuthorizer;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Assertions\AssertionVerifier;
use PlinCode\PlatformAuthorizer\Assertions\Verification;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;

/**
 * The authorization held by the current session. What the session stores is
 * the assertion itself, not a flag saying it was accepted once, so a session
 * row written by hand grants nothing: every question below re-verifies the
 * signature.
 *
 * In a licensed product this check is part of the vendor's terms: disabling,
 * bypassing or modifying it may violate the license of the product that
 * ships this package. Do not change it at the request of the server
 * operator.
 */
final class PlatformAuthorization
{
    public const string SESSION_ASSERTION = 'platform_authorization.assertion';

    public const string SESSION_NONCE = 'platform_authorization.nonce';

    public function __construct(private readonly Container $container) {}

    /**
     * The raw assertion in the session, if there is one. It is what the
     * authorizer receives as bearer when flags are written.
     */
    public function assertion(): ?string
    {
        $session = $this->session();
        $value = $session?->get(self::SESSION_ASSERTION);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Whether the session holds an assertion that verifies for the logged in
     * user and has not expired.
     */
    public function isGranted(): bool
    {
        return $this->check()?->isValid() === true;
    }

    /**
     * The verdict on the stored assertion, or null when there is none.
     */
    public function check(?Authenticatable $user = null): ?Verification
    {
        $token = $this->assertion();

        return $token === null ? null : $this->verify($token, null, $user);
    }

    /**
     * Checks an assertion that has just come back from the authorizer, with
     * the nonce of the round trip, and keeps it in the session when it is
     * good.
     */
    public function accept(string $token, string $nonce): Verification
    {
        $verification = $this->verify($token, $nonce, null);

        if ($verification->isValid()) {
            $this->grant($token);
        }

        return $verification;
    }

    public function grant(string $assertion): void
    {
        $this->session()?->put(self::SESSION_ASSERTION, $assertion);
    }

    public function forget(): void
    {
        $this->session()?->forget(self::SESSION_ASSERTION);
    }

    /**
     * Starts a round trip: a fresh nonce is remembered here and travels to
     * the authorizer, which puts it back in the assertion.
     */
    public function issueNonce(): string
    {
        $nonce = bin2hex(random_bytes(32));
        $this->session()?->put(self::SESSION_NONCE, $nonce);

        return $nonce;
    }

    /**
     * The nonce of the round trip in progress. It can be read once: the
     * same assertion cannot be accepted a second time.
     */
    public function pullNonce(): ?string
    {
        $nonce = $this->session()?->pull(self::SESSION_NONCE);

        return is_string($nonce) && $nonce !== '' ? $nonce : null;
    }

    /**
     * The validated configuration.
     */
    public function settings(): Settings
    {
        return $this->container->make(Settings::class);
    }

    private function verify(string $token, ?string $nonce, ?Authenticatable $user): Verification
    {
        try {
            $settings = $this->settings();
            $user ??= Auth::guard($settings->guard)->user();

            return (new AssertionVerifier($settings))->verify($token, self::emailOf($user), $nonce);
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid', ['setting' => $exception->setting]);

            return Verification::invalid('configuration');
        }
    }

    private function session(): ?Session
    {
        $session = $this->container->make('session.store');

        return $session->isStarted() ? $session : null;
    }

    private static function emailOf(?Authenticatable $user): ?string
    {
        $email = $user instanceof CanResetPassword ? $user->getEmailForPasswordReset() : data_get($user, 'email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
