<?php

namespace PlinCode\PlatformAuthorizer\Assertions;

/**
 * The claims of an authorization issued by the vendor's authorizer.
 */
final readonly class Assertion
{
    public function __construct(
        public string $issuer,
        public string $audience,
        public string $product,
        public string $subject,
        public string $email,
        public string $nonce,
        public int $issuedAt,
        public int $expiresAt,
        public string $id,
    ) {}

    /**
     * Null unless every claim is present with exactly the expected type: the
     * payload is untrusted until its shape has been checked.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function fromPayload(array $payload): ?self
    {
        $strings = [];

        foreach (['iss', 'aud', 'prd', 'sub', 'email', 'nonce', 'jti'] as $claim) {
            $value = $payload[$claim] ?? null;

            if (! is_string($value) || $value === '') {
                return null;
            }

            $strings[$claim] = $value;
        }

        $issuedAt = $payload['iat'] ?? null;
        $expiresAt = $payload['exp'] ?? null;

        if (! is_int($issuedAt) || ! is_int($expiresAt)) {
            return null;
        }

        return new self(
            $strings['iss'],
            $strings['aud'],
            $strings['prd'],
            $strings['sub'],
            $strings['email'],
            $strings['nonce'],
            $issuedAt,
            $expiresAt,
            $strings['jti'],
        );
    }
}
