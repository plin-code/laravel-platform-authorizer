<?php

namespace PlinCode\PlatformAuthorizer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Client\AuthorizerClient;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Http\DeniedResponse;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;

/**
 * Starts the round trip: a nonce is remembered in the session and the
 * browser goes to the authorizer, which will sign it into the assertion.
 */
final class RedirectController
{
    public function __invoke(PlatformAuthorization $authorization): RedirectResponse|Response
    {
        try {
            $url = app(AuthorizerClient::class)->authorizeUrl($authorization->issueNonce());
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid', ['setting' => $exception->setting]);

            return DeniedResponse::make();
        }

        return redirect()->away($url);
    }
}
