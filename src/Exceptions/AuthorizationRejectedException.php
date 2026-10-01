<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The authorizer, or this installation, refuses to act for the current user.
 */
class AuthorizationRejectedException extends HttpException
{
    /**
     * The message reaches the user, on the 403 page or in the Livewire error
     * modal, so it comes from the translations of the package.
     */
    public function __construct(?string $message = null)
    {
        parent::__construct(403, $message ?? __('platform-authorizer::messages.authorization_refused'));
    }

    public static function forFlagWrite(): self
    {
        return new self(__('platform-authorizer::messages.flags_need_authorization'));
    }
}
