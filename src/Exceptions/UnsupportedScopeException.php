<?php

namespace PlinCode\PlatformAuthorizer\Exceptions;

use LogicException;

/**
 * A flag was written for a scope other than the global one. The authorizer
 * signs one global flag set per installation, so a per user value has
 * nowhere to live and silently making it global would be wrong.
 */
class UnsupportedScopeException extends LogicException {}
