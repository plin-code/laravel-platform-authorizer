<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use RuntimeException;

/**
 * Raised when the package configuration cannot be trusted. The message names
 * the offending setting and never its value, because values can be keys.
 */
class InvalidConfigurationException extends RuntimeException
{
    public static function for(string $setting, string $reason): self
    {
        return new self("Invalid platform-authorizer setting [{$setting}]: {$reason}.");
    }
}
