<?php

namespace PlinCode\PlatformAuthorizer\Assertions;

/**
 * The outcome of checking an assertion. The reason is a short code that is
 * safe to log: it never carries a claim, an email or a token.
 */
final readonly class Verification
{
    private function __construct(
        public VerificationStatus $status,
        public ?Assertion $assertion,
        public ?string $reason,
    ) {}

    public static function valid(Assertion $assertion): self
    {
        return new self(VerificationStatus::Valid, $assertion, null);
    }

    public static function expired(Assertion $assertion): self
    {
        return new self(VerificationStatus::Expired, $assertion, 'expired');
    }

    public static function invalid(string $reason): self
    {
        return new self(VerificationStatus::Invalid, null, $reason);
    }

    public function isValid(): bool
    {
        return $this->status === VerificationStatus::Valid;
    }

    public function isExpired(): bool
    {
        return $this->status === VerificationStatus::Expired;
    }
}
