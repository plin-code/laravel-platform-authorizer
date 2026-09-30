<?php

namespace PlinCode\PlatformAuthorizer\Jws;

enum JwsFailure: string
{
    /** Not a well formed compact JWS, or a header or payload of the wrong shape. */
    case Malformed = 'malformed';

    /** The key id in the header is not in the configuration, or its key is unusable. */
    case UnknownKid = 'unknown_kid';

    case BadSignature = 'bad_signature';
}
