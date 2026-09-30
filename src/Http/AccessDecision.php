<?php

namespace PlinCode\PlatformAuthorizer\Http;

enum AccessDecision
{
    /** Serve the request. */
    case Allow;

    /** Send the browser through the authorizer. Only ever for a navigation. */
    case Authorize;

    /** Refuse with a 403. */
    case Forbidden;

    /** Refuse with a 419, which makes Livewire reload the page. */
    case Expired;
}
