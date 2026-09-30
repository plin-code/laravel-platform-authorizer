<?php

namespace PlinCode\PlatformAuthorizer\Http;

use Livewire\Component;

/**
 * Refuses to hydrate a protected Livewire component without a valid
 * authorization, whatever the request says about where it comes from.
 *
 * Livewire replays a route's persistent middleware on the path recorded in
 * the snapshot and replays nothing when that path matches no route. The
 * snapshot is signed with the application key, which the installation's
 * owner holds, so the path can be forged. This check hangs on the component
 * instead, and a forged path does not get around it.
 */
final class ProtectedComponentGuard
{
    public static function check(Component $component): void
    {
        if (! self::isProtected($component::class)) {
            return;
        }

        match (app(AccessDecider::class)->decide(livewireUpdate: true)) {
            AccessDecision::Allow => null,
            AccessDecision::Expired => abort(419),
            AccessDecision::Authorize, AccessDecision::Forbidden => abort(403),
        };
    }

    /**
     * The namespaces are read straight from the configuration, without the
     * validation of the other settings: a broken key list must not make the
     * components unprotected.
     */
    private static function isProtected(string $class): bool
    {
        $namespaces = config('platform-authorizer.protected_livewire_namespaces');

        foreach (is_array($namespaces) ? $namespaces : [] as $namespace) {
            if (is_string($namespace) && $namespace !== '' && str_starts_with($class, rtrim($namespace, '\\').'\\')) {
                return true;
            }
        }

        return false;
    }
}
