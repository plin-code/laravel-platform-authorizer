<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Client\AuthorizerClient;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;

/**
 * Changes the flags of this installation. Nothing is written here: the
 * authorizer signs the new flag set, on the strength of the assertion of the
 * current session, and the signed manifest it returns is what gets stored.
 */
final class FlagWriter
{
    public function __construct(
        private readonly AuthorizerClient $client,
        private readonly ManifestRepository $repository,
        private readonly PlatformAuthorization $authorization,
    ) {}

    /**
     * Replaces the whole flag set.
     *
     * @param  array<array-key, bool>  $flags
     *
     * @throws AuthorizationExpiredException when the assertion of the session has expired
     * @throws AuthorizationRejectedException when there is no assertion, or the authorizer refuses it
     * @throws AuthorizerUnavailableException when the authorizer cannot be used or answers something untrustworthy
     */
    public function write(array $flags): void
    {
        $assertion = $this->authorization->assertion();

        if ($assertion === null) {
            throw AuthorizationRejectedException::forFlagWrite();
        }

        $outcome = $this->repository->store($this->client->writeFlags($assertion, $flags));

        if ($outcome !== StoreOutcome::Stored) {
            Log::warning('Platform authorizer answered with a manifest that was not accepted', ['outcome' => $outcome->value]);

            throw new AuthorizerUnavailableException;
        }
    }
}
