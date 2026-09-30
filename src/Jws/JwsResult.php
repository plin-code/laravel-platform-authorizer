<?php

namespace PlinCode\PlatformAuthorizer\Jws;

/**
 * The outcome of verifying a token: the payload when it verifies, otherwise
 * the reason it did not. The key id is the one the header declared, kept
 * only when it is a short plain string, so it is safe to log or report.
 */
final readonly class JwsResult
{
    /**
     * @param  array<array-key, mixed>|null  $payload
     */
    private function __construct(
        public ?array $payload,
        public ?JwsFailure $failure,
        public ?string $kid,
    ) {}

    /**
     * @param  array<array-key, mixed>  $payload
     */
    public static function verified(array $payload, string $kid): self
    {
        return new self($payload, null, $kid);
    }

    public static function failed(JwsFailure $failure, ?string $kid = null): self
    {
        return new self(null, $failure, $kid);
    }

    public function passed(): bool
    {
        return $this->payload !== null;
    }
}
