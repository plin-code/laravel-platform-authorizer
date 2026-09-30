<?php

namespace PlinCode\PlatformAuthorizer\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static string|null assertion()
 * @method static bool isGranted()
 * @method static \PlinCode\PlatformAuthorizer\Assertions\Verification|null check(?\Illuminate\Contracts\Auth\Authenticatable $user = null)
 * @method static \PlinCode\PlatformAuthorizer\Assertions\Verification accept(string $token, string $nonce)
 * @method static \PlinCode\PlatformAuthorizer\Settings settings()
 * @method static void grant(string $assertion)
 * @method static void forget()
 * @method static string issueNonce()
 * @method static string|null pullNonce()
 *
 * @see \PlinCode\PlatformAuthorizer\PlatformAuthorization
 */
class PlatformAuthorization extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \PlinCode\PlatformAuthorizer\PlatformAuthorization::class;
    }
}
