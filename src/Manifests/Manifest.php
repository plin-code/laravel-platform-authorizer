<?php

namespace PlinCode\PlatformAuthorizer\Manifests;

use stdClass;

/**
 * The set of feature flags the authorizer signed for one installation.
 */
final readonly class Manifest
{
    /**
     * @param  array<string, bool>  $flags
     */
    public function __construct(
        public string $installation,
        public string $product,
        public int $version,
        public int $issuedAt,
        public array $flags,
    ) {}

    /**
     * Null unless every field is present with exactly the expected type.
     * The flags must be a JSON object (as decoded by CompactJws, so a list
     * is refused), with a boolean for every name.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $installation = $payload['aud'] ?? null;
        $product = $payload['prd'] ?? null;
        $version = $payload['ver'] ?? null;
        $issuedAt = $payload['iat'] ?? null;
        $flags = $payload['flags'] ?? null;

        if (! is_string($installation) || $installation === ''
            || ! is_string($product) || $product === ''
            || ! is_int($version) || $version < 1
            || ! is_int($issuedAt)
            || ! $flags instanceof stdClass) {
            return null;
        }

        $checked = [];

        foreach (get_object_vars($flags) as $name => $value) {
            // PHP hands over a name made of digits as an integer.
            $name = (string) $name;

            if ($name === '' || ! is_bool($value)) {
                return null;
            }

            $checked[$name] = $value;
        }

        return new self($installation, $product, $version, $issuedAt, $checked);
    }
}
