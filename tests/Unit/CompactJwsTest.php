<?php

use PlinCode\PlatformAuthorizer\Jws\CompactJws;
use PlinCode\PlatformAuthorizer\Jws\JwsFailure;
use PlinCode\PlatformAuthorizer\Testing\Signer;

function b64url(string $text): string
{
    return rtrim(strtr(base64_encode($text), '+/', '-_'), '=');
}

it('returns the payload of a token signed by a known key', function () {
    $signer = new Signer;
    $token = $signer->sign(['aud' => 'acme', 'ver' => 2]);

    $result = CompactJws::verify($token, [$signer->keyId() => $signer->publicKey()]);

    expect($result->passed())->toBeTrue()
        ->and($result->payload)->toBe(['aud' => 'acme', 'ver' => 2])
        ->and($result->failure)->toBeNull()
        ->and($result->kid)->toBe('test-key-1');
});

it('accepts a token when several keys are configured', function () {
    $signer = new Signer;
    $other = new Signer('another-seed', 'other-key');
    $token = $signer->sign(['ok' => true]);

    expect(CompactJws::verify($token, [
        $other->keyId() => $other->publicKey(),
        $signer->keyId() => $signer->publicKey(),
    ])->payload)->toBe(['ok' => true]);
});

it('refuses a token whose key id is not configured any more', function () {
    $signer = new Signer;
    $other = new Signer('another-seed', 'other-key');

    $result = CompactJws::verify($signer->sign(['ok' => true]), [$other->keyId() => $other->publicKey()]);

    expect($result->passed())->toBeFalse()
        ->and($result->failure)->toBe(JwsFailure::UnknownKid)
        ->and($result->kid)->toBe('test-key-1');
});

it('refuses a token that does not verify, and says why', function (Closure $build, JwsFailure $failure, ?string $kid) {
    $signer = new Signer;
    $result = CompactJws::verify($build($signer), [$signer->keyId() => $signer->publicKey()]);

    expect($result->passed())->toBeFalse()
        ->and($result->payload)->toBeNull()
        ->and($result->failure)->toBe($failure)
        ->and($result->kid)->toBe($kid);
})->with([
    'empty' => [fn () => '', JwsFailure::Malformed, null],
    'two parts' => [fn () => 'a.b', JwsFailure::Malformed, null],
    'four parts' => [fn () => 'a.b.c.d', JwsFailure::Malformed, null],
    'a payload byte changed' => [function (Signer $signer): string {
        [$header, $payload, $signature] = explode('.', $signer->sign(['ver' => 1]));

        return $header.'.'.b64url('{"ver":9}').'.'.$signature;
    }, JwsFailure::BadSignature, 'test-key-1'],
    'a signature byte changed' => [function (Signer $signer): string {
        [$header, $payload, $signature] = explode('.', $signer->sign(['ver' => 1]));
        $bytes = (string) base64_decode(strtr($signature, '-_', '+/'), true);
        $bytes[0] = chr(ord($bytes[0]) ^ 1);

        return $header.'.'.$payload.'.'.b64url($bytes);
    }, JwsFailure::BadSignature, 'test-key-1'],
    'signed by another key under the same key id' => [fn () => (new Signer('another-seed'))->sign(['ver' => 1]), JwsFailure::BadSignature, 'test-key-1'],
    'signature too short' => [function (Signer $signer): string {
        [$header, $payload] = explode('.', $signer->sign(['ver' => 1]));

        return $header.'.'.$payload.'.'.b64url('short');
    }, JwsFailure::Malformed, 'test-key-1'],
    'signature not base64url' => [function (Signer $signer): string {
        [$header, $payload] = explode('.', $signer->sign(['ver' => 1]));

        return $header.'.'.$payload.'.@@@';
    }, JwsFailure::Malformed, 'test-key-1'],
    'padding characters in the header' => [function (Signer $signer): string {
        [$header, $payload, $signature] = explode('.', $signer->sign(['ver' => 1]));

        return $header.'=.'.$payload.'.'.$signature;
    }, JwsFailure::Malformed, null],
    'header not json' => [fn (Signer $signer) => $signer->signRaw('nope', '{}'), JwsFailure::Malformed, null],
    'header a json list' => [fn (Signer $signer) => $signer->signRaw('["EdDSA"]', '{}'), JwsFailure::Malformed, null],
    'algorithm none' => [fn (Signer $signer) => $signer->signRaw('{"alg":"none","kid":"test-key-1"}', '{}'), JwsFailure::Malformed, null],
    'algorithm not a string' => [fn (Signer $signer) => $signer->signRaw('{"alg":{"x":1},"kid":"test-key-1"}', '{}'), JwsFailure::Malformed, null],
    'algorithm missing' => [fn (Signer $signer) => $signer->signRaw('{"kid":"test-key-1"}', '{}'), JwsFailure::Malformed, null],
    'key id missing' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA"}', '{}'), JwsFailure::Malformed, null],
    'key id an array' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":["test-key-1"]}', '{}'), JwsFailure::Malformed, null],
    'key id a float' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":1.5}', '{}'), JwsFailure::Malformed, null],
    'key id an integer' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":1}', '{}'), JwsFailure::Malformed, null],
    'key id null' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":null}', '{}'), JwsFailure::Malformed, null],
    'key id empty' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":""}', '{}'), JwsFailure::Malformed, null],
    'unknown key id' => [fn (Signer $signer) => $signer->sign(['ver' => 1], 'gone-key'), JwsFailure::UnknownKid, 'gone-key'],
    'unknown key id that is not printable' => [fn (Signer $signer) => $signer->sign(['ver' => 1], "gone\nkey"), JwsFailure::UnknownKid, null],
    'unknown key id that is too long' => [fn (Signer $signer) => $signer->sign(['ver' => 1], str_repeat('k', 101)), JwsFailure::UnknownKid, null],
    'payload not json' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', 'nope'), JwsFailure::Malformed, 'test-key-1'],
    'payload a json scalar' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', '42'), JwsFailure::Malformed, 'test-key-1'],
    'payload a json list' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', '[1,2]'), JwsFailure::Malformed, 'test-key-1'],
]);

it('treats a configured public key that is not usable as an unknown key', function (mixed $publicKey) {
    $signer = new Signer;

    $result = CompactJws::verify($signer->sign(['ok' => true]), [$signer->keyId() => $publicKey]);

    expect($result->passed())->toBeFalse()
        ->and($result->failure)->toBe(JwsFailure::UnknownKid);
})->with([
    'not base64' => ['@@@'],
    'too short' => [base64_encode('short')],
    'too long' => [base64_encode(str_repeat('a', 33))],
    'empty' => [''],
    'not a string' => [['x']],
]);
