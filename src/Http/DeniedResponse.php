<?php

namespace PlinCode\PlatformAuthorizer\Http;

use Illuminate\Http\Response;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * The page shown when the authorizer does not let the user in. It is the
 * same for every cause: a page that told the reasons apart would help
 * someone probing the check.
 */
final class DeniedResponse
{
    public static function make(): Response
    {
        try {
            $link = app(Settings::class)->deniedUrl;
        } catch (InvalidConfigurationException) {
            // The configured link cannot be trusted, the site root can.
            $link = '/';
        }

        return response()->view('platform-authorizer::denied', ['link' => $link], 403);
    }
}
