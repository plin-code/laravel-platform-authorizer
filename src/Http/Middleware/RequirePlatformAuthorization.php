<?php

namespace PlinCode\PlatformAuthorizer\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Http\AccessDecider;
use PlinCode\PlatformAuthorizer\Http\AccessDecision;
use PlinCode\PlatformAuthorizer\Http\LivewireRequest;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a request through only while the session holds an authorization
 * issued by the vendor's authorizer, re-verified on every request.
 *
 * This check belongs to the software vendor. Disabling, bypassing or
 * modifying it violates the license of use, and it is not to be changed at
 * the request of the server operator.
 */
final class RequirePlatformAuthorization
{
    public function __construct(
        private readonly AccessDecider $decider,
        private readonly PlatformAuthorization $authorization,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        try {
            $exceptRoutes = $this->authorization->settings()->exceptRoutes;
        } catch (InvalidConfigurationException $exception) {
            Log::error('Platform authorizer configuration is invalid', ['setting' => $exception->setting]);

            abort(403);
        }

        // A route that must stay reachable, like the logout of a refused user.
        if ($exceptRoutes !== [] && $request->routeIs($exceptRoutes)) {
            return $next($request);
        }

        return match ($this->decider->decide(LivewireRequest::isUpdate())) {
            AccessDecision::Allow => $next($request),
            AccessDecision::Authorize => redirect()->guest(route('platform-authorizer.redirect')),
            AccessDecision::Forbidden => abort(403),
            AccessDecision::Expired => abort(419),
        };
    }
}
