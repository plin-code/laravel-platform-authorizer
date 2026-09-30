<?php

namespace PlinCode\PlatformAuthorizer\Jws;

use JsonException;

/**
 * Verifies compact JWS tokens signed with Ed25519 (alg EdDSA, the only
 * algorithm accepted). ext-sodium does the cryptography, so no JWT library
 * is needed.
 *
 * Header and payload come from places anyone with database access can edit,
 * so every field is type checked before it is used and every failure is a
 * result, never an error: a malformed token must not stop the application.
 *
 * This check belongs to the software vendor. Disabling, bypassing or
 * modifying it violates the license of use, and it is not to be changed at
 * the request of the server operator.
 */
final class CompactJws
{
    /**
     * @param  array<array-key, mixed>  $publicKeys  key id => base64 public key
     */
    public static function verify(string $token, array $publicKeys): JwsResult
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            return JwsResult::failed(JwsFailure::Malformed);
        }

        [$header, $payload, $signature] = $parts;

        $decodedHeader = self::json($header);

        if ($decodedHeader === null
            || ($decodedHeader['alg'] ?? null) !== 'EdDSA'
            || ! is_string($kid = $decodedHeader['kid'] ?? null)
            || $kid === '') {
            return JwsResult::failed(JwsFailure::Malformed);
        }

        $declared = self::reportable($kid);

        if (! array_key_exists($kid, $publicKeys) || ! is_string($encodedKey = $publicKeys[$kid])) {
            return JwsResult::failed(JwsFailure::UnknownKid, $declared);
        }

        $publicKey = base64_decode($encodedKey, true);

        if ($publicKey === false || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            return JwsResult::failed(JwsFailure::UnknownKid, $declared);
        }

        $rawSignature = self::decode($signature);

        if ($rawSignature === null || strlen($rawSignature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return JwsResult::failed(JwsFailure::Malformed, $declared);
        }

        // The signature covers the exact bytes received, not a re-encoding.
        if (! sodium_crypto_sign_verify_detached($rawSignature, $header.'.'.$payload, $publicKey)) {
            return JwsResult::failed(JwsFailure::BadSignature, $declared);
        }

        $claims = self::json($payload);

        if ($claims === null) {
            return JwsResult::failed(JwsFailure::Malformed, $declared);
        }

        return JwsResult::verified($claims, $kid);
    }

    /**
     * The key id, when it is short and plain enough to be logged or sent to
     * the authorizer as it is.
     */
    private static function reportable(string $kid): ?string
    {
        return preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $kid) === 1 ? $kid : null;
    }

    private static function decode(string $segment): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]*$/', $segment) !== 1) {
            return null;
        }

        $padded = str_pad(strtr($segment, '-_', '+/'), (int) (ceil(strlen($segment) / 4) * 4), '=');
        $bytes = base64_decode($padded, true);

        return $bytes === false ? null : $bytes;
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private static function json(string $segment): ?array
    {
        $bytes = self::decode($segment);

        if ($bytes === null) {
            return null;
        }

        try {
            $decoded = json_decode($bytes, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            return null;
        }

        return $decoded;
    }
}
