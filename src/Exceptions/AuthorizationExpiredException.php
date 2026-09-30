<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authorizer refused a write because the assertion behind it has expired.
 * It renders with the configured status, 419 by default, which makes
 * Livewire reload the page, and the reload goes through the authorizer again.
 */
class AuthorizationExpiredException extends HttpException
{
    public function __construct(int $status = 419)
    {
        parent::__construct($status, 'The platform authorization has expired.');
    }
}
