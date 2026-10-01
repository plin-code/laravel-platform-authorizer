<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

/**
 * The outcome of checking a manifest: the manifest when it can be trusted,
 * otherwise the reason it was refused. The reason is one of the codes the
 * AssertionRejected event carries, safe to log.
 */
final readonly class ManifestVerification
{
    private function __construct(
        public ?Manifest $manifest,
        public ?string $reason,
    ) {}

    public static function valid(Manifest $manifest): self
    {
        return new self($manifest, null);
    }

    public static function refused(string $reason): self
    {
        return new self(null, $reason);
    }
}
