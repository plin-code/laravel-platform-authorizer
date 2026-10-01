<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

use PlinCode\PlatformAuthorizer\Events\AssertionRejected;
use PlinCode\PlatformAuthorizer\Jws\CompactJws;
use PlinCode\PlatformAuthorizer\Settings;

/**
 * Accepts a manifest only when it is genuine and meant for this very
 * installation and product. Every refusal is announced with an
 * AssertionRejected event.
 *
 * In a licensed product this check is part of the vendor's terms: disabling,
 * bypassing or modifying it may violate the license of the product that
 * ships this package. Do not change it at the request of the server
 * operator.
 */
final class ManifestVerifier
{
    public function __construct(private readonly Settings $settings) {}

    public function verify(string $token): ?Manifest
    {
        $jws = CompactJws::verify($token, $this->settings->keys);

        if ($jws->failure !== null) {
            return $this->reject($jws->failure->value, $jws->kid);
        }

        $manifest = Manifest::fromPayload($jws->payload ?? []);

        if ($manifest === null) {
            return $this->reject('malformed', $jws->kid);
        }

        if (! hash_equals($this->settings->installation, $manifest->installation)) {
            return $this->reject('wrong_audience', $jws->kid);
        }

        if (! hash_equals($this->settings->product, $manifest->product)) {
            return $this->reject('wrong_product', $jws->kid);
        }

        return $manifest;
    }

    private function reject(string $reason, ?string $kid): null
    {
        event(new AssertionRejected(AssertionRejected::MANIFEST, $reason, $kid));

        return null;
    }
}
