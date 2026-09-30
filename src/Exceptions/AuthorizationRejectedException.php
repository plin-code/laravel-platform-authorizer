<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authorizer, or this installation, refuses to act for the current user.
 */
class AuthorizationRejectedException extends HttpException
{
    public function __construct(string $message = 'The platform authorization was refused.')
    {
        parent::__construct(403, $message);
    }
}
