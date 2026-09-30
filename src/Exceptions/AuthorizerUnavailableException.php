<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authorizer could not be used: unreachable, too slow, overloaded or
 * answering something unexpected. Callers fail closed.
 */
class AuthorizerUnavailableException extends HttpException
{
    public function __construct(string $message = 'The platform authorizer is not available.')
    {
        parent::__construct(503, $message);
    }
}
