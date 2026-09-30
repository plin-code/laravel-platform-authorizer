<?php

use PlinCode\PlatformAuthorizer\Testing\Signer;

it('derives the same key pair from the same seed', function () {
    $first = new Signer('seed-one');
    $second = new Signer('seed-one');
    $other = new Signer('seed-two');

    expect($first->publicKey())->toBe($second->publicKey())
        ->and($first->publicKey())->not->toBe($other->publicKey())
        ->and(strlen((string) base64_decode($first->publicKey(), true)))->toBe(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES);
});

it('signs claims into a compact token that verifies with its public key', function () {
    $signer = new Signer('seed-one', 'kid-one');
    $token = $signer->sign(['aud' => 'acme', 'ver' => 3]);

    [$header, $payload, $signature] = explode('.', $token);

    $decode = fn (string $segment): string => (string) base64_decode(strtr($segment, '-_', '+/'), true);

    expect(json_decode($decode($header), true))->toBe(['alg' => 'EdDSA', 'kid' => 'kid-one'])
        ->and(json_decode($decode($payload), true))->toBe(['aud' => 'acme', 'ver' => 3])
        ->and(sodium_crypto_sign_verify_detached(
            $decode($signature),
            $header.'.'.$payload,
            (string) base64_decode($signer->publicKey(), true),
        ))->toBeTrue();
});

it('signs arbitrary header and payload text for malformed input tests', function () {
    $signer = new Signer('seed-one');
    $token = $signer->signRaw('{"alg":"EdDSA","kid":["x"]}', '{"a":1}');

    expect(explode('.', $token))->toHaveCount(3);
});

it('lets the key id be overridden per token', function () {
    $signer = new Signer('seed-one', 'kid-one');
    $header = explode('.', $signer->sign([], 'kid-two'))[0];

    expect(json_decode((string) base64_decode(strtr($header, '-_', '+/'), true), true)['kid'])->toBe('kid-two');
});
