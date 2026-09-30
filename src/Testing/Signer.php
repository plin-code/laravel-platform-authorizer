<?php

namespace PlinCode\PlatformAuthorizer\Testing;

use stdClass;

/**
 * Signs tokens the way the vendor's authorizer does, with a key pair derived
 * from a seed, so tests never need the real private key. The package never
 * trusts this key on its own: it only works once a test has put its public
 * half in the configuration.
 */
final class Signer
{
    public const string DEFAULT_SEED = 'platform-authorizer-test-seed';

    public const string DEFAULT_KEY_ID = 'test-key-1';

    /** @var non-empty-string */
    private readonly string $keyPair;

    public function __construct(string $seed = self::DEFAULT_SEED, private readonly string $keyId = self::DEFAULT_KEY_ID)
    {
        $this->keyPair = sodium_crypto_sign_seed_keypair(hash('sha256', $seed, true));
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * The base64 public key, in the form the configuration expects.
     */
    public function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey($this->keyPair));
    }

    /**
     * @param  array<string, mixed>  $claims
     */
    public function sign(array $claims, ?string $keyId = null): string
    {
        // The authorizer signs an empty flag set as an object, never as a list.
        if (($claims['flags'] ?? null) === []) {
            $claims['flags'] = new stdClass;
        }

        return $this->signRaw(
            json_encode(['alg' => 'EdDSA', 'kid' => $keyId ?? $this->keyId], JSON_THROW_ON_ERROR),
            json_encode($claims, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Signs header and payload exactly as given, to build tokens whose header
     * or payload is malformed but whose signature is genuine.
     */
    public function signRaw(string $header, string $payload): string
    {
        $signingInput = self::encode($header).'.'.self::encode($payload);
        $signature = sodium_crypto_sign_detached($signingInput, sodium_crypto_sign_secretkey($this->keyPair));

        return $signingInput.'.'.self::encode($signature);
    }

    private static function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
