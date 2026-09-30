<?php

namespace PlinCode\PlatformAuthorizer\Events;

/**
 * Dispatched whenever an assertion or a manifest is refused. An application
 * can listen to it, for instance to tell the vendor about an unexpected key
 * id. It carries a short reason code and the key id the token declared, and
 * never the token, an email or a claim.
 */
final readonly class AssertionRejected
{
    public const string ASSERTION = 'assertion';

    public const string MANIFEST = 'manifest';

    /**
     * @param  string  $subject  what was refused: ASSERTION or MANIFEST
     * @param  string  $reason  one of malformed, unknown_kid, bad_signature, wrong_issuer, wrong_audience, wrong_product, wrong_nonce, email_mismatch, expired
     * @param  string|null  $kid  the key id declared in the header, when it is a short plain string
     */
    public function __construct(
        public string $subject,
        public string $reason,
        public ?string $kid,
    ) {}
}
