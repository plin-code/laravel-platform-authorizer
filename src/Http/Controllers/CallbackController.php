<?php

namespace PlinCode\PlatformAuthorizer\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Http\DeniedResponse;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Completes the round trip. The nonce is taken out of the session before
 * anything is checked, so it serves one attempt whatever happens next, and
 * every failure ends on the same generic page.
 */
final class CallbackController
{
    public function __invoke(Request $request, PlatformAuthorization $authorization): RedirectResponse|Response
    {
        $nonce = $authorization->pullNonce();

        try {
            $home = app(Settings::class)->home;
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid', ['setting' => $exception->setting]);

            return DeniedResponse::make();
        }

        $token = $request->query('assertion');

        if ($request->query('error') !== null) {
            return self::refuse('refused_by_authorizer');
        }

        if (! is_string($token) || $token === '') {
            return self::refuse('missing_assertion');
        }

        if ($nonce === null) {
            return self::refuse('missing_nonce');
        }

        $verification = $authorization->accept($token, $nonce);

        if (! $verification->isValid()) {
            return self::refuse((string) $verification->reason);
        }

        return redirect()->intended($home);
    }

    private static function refuse(string $reason): Response
    {
        Log::warning('Platform authorization refused', ['reason' => $reason]);

        return DeniedResponse::make();
    }
}
