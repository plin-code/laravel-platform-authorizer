<?php

namespace PlinCode\PlatformAuthorizer\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Talks to the vendor's authorizer. Every call has a short timeout and none
 * is retried, so a slow or absent authorizer means access denied, never a
 * hanging request. None follows a redirect, so the assertion sent as bearer
 * never leaves the configured host. Log context holds an endpoint name and a
 * status code and nothing else: no assertion, nonce, email or response body.
 */
final class AuthorizerClient
{
    public function __construct(private readonly Settings $settings) {}

    /**
     * Where the browser goes to start a round trip.
     */
    public function authorizeUrl(string $nonce): string
    {
        if (preg_match('/^[0-9a-f]{64}$/', $nonce) !== 1) {
            throw new InvalidArgumentException('The nonce must be 64 lowercase hexadecimal characters.');
        }

        return $this->settings->url.'/v1/authorize?'.http_build_query([
            'product' => $this->settings->product,
            'installation' => $this->settings->installation,
            'nonce' => $nonce,
        ]);
    }

    /**
     * The token of the latest manifest, or null when the authorizer has none
     * for this installation yet.
     *
     * @throws AuthorizerUnavailableException
     */
    public function fetchManifest(): ?string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request->get('/v1/flags/'.rawurlencode($this->settings->installation)), 'flags.read');

        if ($response->status() === 404) {
            return null;
        }

        return $this->manifestFrom($response, 'flags.read');
    }

    /**
     * Asks the authorizer to sign a new flag set, presenting the assertion of
     * the current session as bearer, and returns the new manifest token.
     *
     * @param  array<array-key, bool>  $flags  PHP turns a name made of digits into an integer key
     *
     * @throws AuthorizationExpiredException
     * @throws AuthorizationRejectedException
     * @throws AuthorizerUnavailableException
     */
    public function writeFlags(string $assertion, array $flags): string
    {
        $response = $this->send(fn (PendingRequest $request): Response => $request
            ->withToken($assertion)
            ->post('/v1/flags', [
                'installation' => $this->settings->installation,
                // A PHP array that is empty or has the names 0, 1, 2... would be
                // sent as a list, which the authorizer refuses: the flag set
                // is a JSON object.
                'flags' => array_is_list($flags) ? (object) $flags : $flags,
            ]), 'flags.write');

        if ($response->status() === 401 && $response->json('error') === 'expired') {
            throw new AuthorizationExpiredException($this->settings->expiredStatus);
        }

        if ($response->status() === 401 || $response->status() === 403) {
            Log::warning('Platform authorizer refused a flag write', ['endpoint' => 'flags.write', 'status' => $response->status()]);

            throw new AuthorizationRejectedException;
        }

        return $this->manifestFrom($response, 'flags.write');
    }

    private function manifestFrom(Response $response, string $endpoint): string
    {
        $manifest = $response->successful() ? $response->json('manifest') : null;

        if (! is_string($manifest) || $manifest === '') {
            Log::warning('Platform authorizer answered unexpectedly', ['endpoint' => $endpoint, 'status' => $response->status()]);

            throw new AuthorizerUnavailableException;
        }

        return $manifest;
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     *
     * @throws AuthorizerUnavailableException
     */
    private function send(callable $call, string $endpoint): Response
    {
        $request = Http::baseUrl($this->settings->url)
            ->timeout($this->settings->timeout)
            ->connectTimeout($this->settings->timeout)
            // A redirect would carry the bearer to whatever host it names.
            ->withoutRedirecting()
            ->acceptJson();

        try {
            $response = $call($request);
        } catch (ConnectionException) {
            Log::warning('Platform authorizer unreachable', ['endpoint' => $endpoint]);

            throw new AuthorizerUnavailableException;
        }

        if ($response->status() === 429 || $response->status() === 400 || $response->serverError() || $response->redirect()) {
            Log::warning('Platform authorizer unavailable', ['endpoint' => $endpoint, 'status' => $response->status()]);

            throw new AuthorizerUnavailableException;
        }

        return $response;
    }
}
