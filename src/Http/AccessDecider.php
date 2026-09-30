<?php

namespace PlinCode\PlatformAuthorizer\Http;

use Illuminate\Support\Facades\Log;
use PlinCode\PlatformAuthorizer\Assertions\VerificationStatus;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;

/**
 * The one place that decides what to do with a request to the protected
 * area, shared by the middleware and by the Livewire component check so the
 * two can never disagree.
 *
 * A browser navigation can be sent through the authorizer to get a fresh
 * assertion. A Livewire request cannot: the answer would be followed by
 * fetch across domains, so it is refused instead, with the configured
 * `expired_status` (419 or 403) when the only fault is an expired assertion
 * and a 403 otherwise.
 */
final class AccessDecider
{
    public function __construct(private readonly PlatformAuthorization $authorization) {}

    public function decide(bool $livewireUpdate): AccessDecision
    {
        $verification = $this->authorization->check();

        if ($verification === null) {
            return $livewireUpdate ? $this->refuse('missing', AccessDecision::Forbidden) : AccessDecision::Authorize;
        }

        if ($verification->status === VerificationStatus::Valid) {
            return AccessDecision::Allow;
        }

        if ($verification->status === VerificationStatus::Invalid) {
            // A misconfiguration is not the user's doing: keep what they hold.
            if ($verification->reason !== 'configuration') {
                $this->authorization->forget();
            }

            return $this->refuse((string) $verification->reason, AccessDecision::Forbidden);
        }

        if (! $livewireUpdate) {
            return AccessDecision::Authorize;
        }

        // An expired verdict means the configuration was usable a moment ago.
        $grace = $this->authorization->settings()->livewireGraceSeconds;

        $expiresAt = $verification->assertion->expiresAt ?? 0;

        if (now()->getTimestamp() <= $expiresAt + $grace) {
            return AccessDecision::Allow;
        }

        return $this->refuse('expired', AccessDecision::Expired);
    }

    private function refuse(string $reason, AccessDecision $decision): AccessDecision
    {
        Log::warning('Platform request refused', ['reason' => $reason]);

        return $decision;
    }
}
