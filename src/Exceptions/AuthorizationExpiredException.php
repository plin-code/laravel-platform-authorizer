<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authorizer refused a write because the assertion behind it has expired.
 * It renders as a 419, which makes Livewire reload the page, and the reload
 * goes through the authorizer again.
 */
class AuthorizationExpiredException extends HttpException
{
    public function __construct()
    {
        parent::__construct(419, 'The platform authorization has expired.');
    }
}
