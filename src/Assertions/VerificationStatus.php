<?php

namespace PlinCode\PlatformAuthorizer\Assertions;

enum VerificationStatus: string
{
    case Valid = 'valid';

    /** Genuine and meant for this user and installation, but past its expiry. */
    case Expired = 'expired';

    case Invalid = 'invalid';
}
