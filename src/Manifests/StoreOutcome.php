<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

enum StoreOutcome: string
{
    /** The manifest is now the one in use. */
    case Stored = 'stored';

    /** Genuine, but with a version lower than the one already stored. */
    case Older = 'older';

    /** Not a genuine manifest for this installation and product. */
    case Invalid = 'invalid';
}
