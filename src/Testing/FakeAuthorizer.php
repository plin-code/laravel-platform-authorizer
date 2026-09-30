<?php

namespace PlinCode\PlatformAuthorizer\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use LogicException;
use PlinCode\PlatformAuthorizer\Assertions\Assertion;
use PlinCode\PlatformAuthorizer\Assertions\AssertionVerifier;
use PlinCode\PlatformAuthorizer\Jws\CompactJws;
use PlinCode\PlatformAuthorizer\Manifests\Manifest;
use PlinCode\PlatformAuthorizer\Manifests\ManifestCache;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Stands in for the vendor's authorizer in the tests of an application.
 *
 * It trusts a key pair derived from a fixed seed, adds its public half to the
 * configured keys, and answers the authorizer's HTTP endpoints with the same
 * rules as the real service: a flag write needs a genuine, unexpired
 * assertion as bearer and gets back a manifest signed with the next version.
 * It refuses to be installed anywhere but in a test run, so the well known
 * seed can never become a way into a real installation.
 */
final class FakeAuthorizer
{
    private int $version = 0;

    private ?string $latest = null;

    private int $writes = 0;

    /** @var list<array{type: string, reason: string}> */
    private array $events = [];

    private function __construct(private readonly Signer $signer) {}

    /**
     * Trusts the test key and starts answering for the configured
     * authorizer. Call it in the set up of a test, after the configuration
     * of the application is in place.
     */
    public static function install(): self
    {
        if (! app()->runningUnitTests()) {
            throw new LogicException('The fake authorizer can only be installed while running tests.');
        }

        $signer = new Signer;
        $keys = (array) config('platform-authorizer.keys');
        $keys[$signer->keyId()] = $signer->publicKey();
        config()->set('platform-authorizer.keys', $keys);

        $fake = new self($signer);
        $fake->answer();

        return $fake;
    }

    public function signer(): Signer
    {
        return $this->signer;
    }

    /**
     * Puts a valid authorization in the session, for the given user or, when
     * there is none, for a placeholder vendor address. Claims can be
     * overridden and another signer used, to build an authorization that
     * must be refused.
     *
     * @param  array<string, mixed>  $claims
     */
    public function grant(?Authenticatable $user = null, array $claims = [], ?Signer $signer = null): string
    {
        $settings = app(Settings::class);
        $now = now()->getTimestamp();

        $token = ($signer ?? $this->signer)->sign([
            'iss' => $settings->url,
            'aud' => $settings->installation,
            'prd' => $settings->product,
            'sub' => 'github:1001',
            'email' => self::emailOf($user) ?? 'vendor@example.test',
            'nonce' => bin2hex(random_bytes(32)),
            'iat' => $now,
            'exp' => $now + 3600,
            'jti' => bin2hex(random_bytes(16)),
            ...$claims,
        ]);

        $session = app('session.store');

        if (! $session->isStarted()) {
            $session->start();
        }

        app(PlatformAuthorization::class)->grant($token);

        return $token;
    }

    /**
     * Replaces the flags as if the authorizer had signed them, without
     * needing an authorization. Meant to put a test in a known state.
     *
     * @param  array<string, bool>  $flags
     */
    public function setFlags(array $flags): void
    {
        $this->latest = $this->issue($flags);

        app(ManifestRepository::class)->store($this->latest);

        $this->refresh();
    }

    /**
     * @return array<string, bool>
     */
    public function flags(): array
    {
        $payload = $this->latest === null ? null : CompactJws::verify($this->latest, [$this->signer->keyId() => $this->signer->publicKey()])->payload;

        return $payload === null ? [] : Manifest::fromPayload($payload)->flags ?? [];
    }

    /** The version of the latest manifest the authorizer issued, 0 when none. */
    public function version(): int
    {
        return $this->version;
    }

    /** How many flag writes the authorizer accepted. */
    public function writes(): int
    {
        return $this->writes;
    }

    /**
     * @return list<array{type: string, reason: string}>
     */
    public function events(): array
    {
        return $this->events;
    }

    private function answer(): void
    {
        $base = rtrim((string) config('platform-authorizer.url'), '/');
        $installation = (string) config('platform-authorizer.installation');

        Http::fake([
            $base.'/v1/flags/'.$installation => fn () => $this->latest === null
                ? Http::response(['error' => 'not_found'], 404)
                : Http::response(['manifest' => $this->latest]),
            $base.'/v1/flags' => fn (Request $request) => $this->write($request),
            $base.'/v1/events' => function (Request $request) {
                $this->events[] = ['type' => (string) $request['type'], 'reason' => (string) $request['reason']];

                return Http::response('', 202);
            },
        ]);
    }

    private function write(Request $request): mixed
    {
        $bearer = $request->header('Authorization')[0] ?? '';
        $token = str_starts_with($bearer, 'Bearer ') ? substr($bearer, 7) : '';

        $payload = CompactJws::verify($token, [$this->signer->keyId() => $this->signer->publicKey()])->payload;
        $assertion = $payload === null ? null : Assertion::fromPayload($payload);

        if ($assertion === null) {
            return Http::response(['error' => 'invalid'], 401);
        }

        if (now()->getTimestamp() > $assertion->expiresAt + AssertionVerifier::LEEWAY_SECONDS) {
            return Http::response(['error' => 'expired'], 401);
        }

        $this->latest = $this->issue($request['flags']);
        $this->writes++;

        return Http::response(['manifest' => $this->latest]);
    }

    /**
     * @param  array<string, bool>  $flags
     */
    private function issue(array $flags): string
    {
        return $this->signer->sign([
            'aud' => config('platform-authorizer.installation'),
            'prd' => config('platform-authorizer.product'),
            'ver' => ++$this->version,
            'iat' => now()->getTimestamp(),
            'flags' => $flags,
        ]);
    }

    private function refresh(): void
    {
        app(ManifestCache::class)->flush();

        if (class_exists(Feature::class)) {
            Feature::flushCache();
        }
    }

    private static function emailOf(?Authenticatable $user): ?string
    {
        $email = $user instanceof CanResetPassword ? $user->getEmailForPasswordReset() : data_get($user, 'email');

        return is_string($email) && $email !== '' ? $email : null;
    }
}
