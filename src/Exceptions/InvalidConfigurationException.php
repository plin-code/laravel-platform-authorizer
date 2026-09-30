<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use RuntimeException;

/**
 * Raised when the package configuration cannot be trusted. The message names
 * the offending setting and never its value, because values can be keys.
 */
class InvalidConfigurationException extends RuntimeException
{
    public function __construct(string $message, public readonly string $setting = '')
    {
        parent::__construct($message);
    }

    public static function for(string $setting, string $reason): self
    {
        return new self("Invalid platform-authorizer setting [{$setting}]: {$reason}.", $setting);
    }
}
