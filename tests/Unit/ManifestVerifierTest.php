<?php

use Illuminate\Support\Facades\Event;
use PlinCode\PlatformAuthorizer\Events\AssertionRejected;
use PlinCode\PlatformAuthorizer\Manifests\ManifestVerifier;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    $this->signer = new Signer;
    $this->verifier = new ManifestVerifier(settingsFor($this->signer));
});

it('returns the manifest of a genuine token', function () {
    $manifest = $this->verifier->verify($this->signer->sign(manifestClaims()));

    expect($manifest?->installation)->toBe('mizuno-acme')
        ->and($manifest?->product)->toBe('mizuno-run-club')
        ->and($manifest?->version)->toBe(4)
        ->and($manifest?->issuedAt)->toBe(1_790_000_000)
        ->and($manifest?->flags)->toBe(['check-in' => true, 'custom-fields.management' => false]);
});

it('accepts a manifest without flags', function () {
    $manifest = $this->verifier->verify($this->signer->sign(manifestClaims(['flags' => []])));

    expect($manifest?->flags)->toBe([]);
});

/** A genuinely signed manifest whose flags are the given raw json. */
function manifestWithRawFlags(Signer $signer, string $flags): string
{
    $claims = json_encode(array_diff_key(manifestClaims(), ['flags' => true]), JSON_THROW_ON_ERROR);

    return $signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', substr($claims, 0, -1).',"flags":'.$flags.'}');
}

it('accepts flags that are an empty json object', function () {
    expect($this->verifier->verify(manifestWithRawFlags($this->signer, '{}'))?->flags)->toBe([]);
});

it('refuses flags that are a json list, empty or not', function (string $flags) {
    Event::fake([AssertionRejected::class]);

    expect($this->verifier->verify(manifestWithRawFlags($this->signer, $flags)))->toBeNull();

    Event::assertDispatched(AssertionRejected::class, fn (AssertionRejected $event): bool => $event->reason === 'malformed');
})->with([
    'empty list' => ['[]'],
    'list of booleans' => ['[true]'],
    'list of objects' => ['[{"check-in":true}]'],
]);

it('refuses flags that are not a flat object of booleans', function (string $flags) {
    expect($this->verifier->verify(manifestWithRawFlags($this->signer, $flags)))->toBeNull();
})->with([
    'a nested object' => ['{"check-in":{"enabled":true}}'],
    'a nested empty object' => ['{"check-in":{}}'],
    'a nested empty list' => ['{"check-in":[]}'],
    'an empty name' => ['{"":true}'],
    'a string' => ['"all"'],
    'a number' => ['1'],
    'null' => ['null'],
]);

it('keeps a flag named with digits as a flag name', function () {
    $manifest = $this->verifier->verify(manifestWithRawFlags($this->signer, '{"123":true,"0":false,"check-in":true}'));

    expect($manifest)->not->toBeNull()
        ->and($manifest->flags)->toBe(['123' => true, '0' => false, 'check-in' => true])
        ->and(array_key_exists('123', $manifest->flags))->toBeTrue()
        ->and($manifest->flags['123'])->toBeTrue();
});

it('refuses a flag named with digits whose value is not a boolean', function () {
    expect($this->verifier->verify(manifestWithRawFlags($this->signer, '{"123":1}')))->toBeNull();
});

it('refuses a manifest issued for another installation or product', function (array $claims) {
    expect($this->verifier->verify($this->signer->sign(manifestClaims($claims))))->toBeNull();
})->with([
    'other installation' => [['aud' => 'someone-else']],
    'other product' => [['prd' => 'another-product']],
]);

it('refuses a genuine manifest whose fields have the wrong type', function (array $claims) {
    expect($this->verifier->verify($this->signer->sign(manifestClaims($claims))))->toBeNull();
})->with([
    'flags as a string' => [['flags' => 'all']],
    'flags as a list' => [['flags' => [true, false]]],
    'flag value as a string' => [['flags' => ['check-in' => 'yes']]],
    'flag value as a number' => [['flags' => ['check-in' => 1]]],
    'flag value null' => [['flags' => ['check-in' => null]]],
    'no flags' => [['flags' => null]],
    'version as a string' => [['ver' => '4']],
    'version as a float' => [['ver' => 4.5]],
    'version zero' => [['ver' => 0]],
    'version negative' => [['ver' => -1]],
    'version missing' => [['ver' => null]],
    'issued at as a string' => [['iat' => 'yesterday']],
    'installation as an array' => [['aud' => ['mizuno-acme']]],
    'product as a number' => [['prd' => 5]],
]);

it('refuses a token that does not verify', function (Closure $build) {
    expect($this->verifier->verify($build($this->signer)))->toBeNull();
})->with([
    'garbage' => [fn () => 'garbage'],
    'unknown key id' => [fn (Signer $signer) => $signer->sign(manifestClaims(), 'gone-key')],
    'another key under the same key id' => [fn () => (new Signer('another-seed'))->sign(manifestClaims())],
    'key id a float' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":1.5}', json_encode(manifestClaims()))],
    'a changed payload' => [function (Signer $signer): string {
        [$header, , $signature] = explode('.', $signer->sign(manifestClaims()));
        $forged = rtrim(strtr(base64_encode(json_encode(manifestClaims(['ver' => 99]))), '+/', '-_'), '=');

        return $header.'.'.$forged.'.'.$signature;
    }],
]);

it('verifies a manifest signed by either key while two are configured', function () {
    $old = new Signer('old-seed', 'key-old');
    $new = new Signer('new-seed', 'key-new');
    $verifier = new ManifestVerifier(settingsFor($old, ['keys' => [
        $old->keyId() => $old->publicKey(),
        $new->keyId() => $new->publicKey(),
    ]]));

    expect($verifier->verify($old->sign(manifestClaims()))?->version)->toBe(4)
        ->and($verifier->verify($new->sign(manifestClaims(['ver' => 5])))?->version)->toBe(5);
});

it('falls back to nothing once the key that signed a manifest is removed from the configuration', function () {
    $old = new Signer('old-seed', 'key-old');
    $new = new Signer('new-seed', 'key-new');
    $verifier = new ManifestVerifier(settingsFor($new));

    expect($verifier->verify($old->sign(manifestClaims())))->toBeNull()
        ->and($verifier->verify($new->sign(manifestClaims())))->not->toBeNull();
});

it('gives the reason of a refusal along with the verdict', function () {
    $retired = new Signer('retired-seed', 'retired-key');

    expect($this->verifier->check($retired->sign(manifestClaims()))->reason)->toBe('unknown_kid')
        ->and($this->verifier->check($retired->sign(manifestClaims()))->manifest)->toBeNull()
        ->and($this->verifier->check($this->signer->sign(manifestClaims()))->reason)->toBeNull()
        ->and($this->verifier->check($this->signer->sign(manifestClaims()))->manifest?->version)->toBe(4);
});

it('announces every refusal with the reason and the declared key id only', function (Closure $build, string $reason, ?string $kid) {
    Event::fake([AssertionRejected::class]);

    $this->verifier->verify($build($this->signer));

    Event::assertDispatchedTimes(AssertionRejected::class, 1);
    Event::assertDispatched(AssertionRejected::class, function (AssertionRejected $event) use ($reason, $kid): bool {
        expect($event->subject)->toBe('manifest')
            ->and($event->reason)->toBe($reason)
            ->and($event->kid)->toBe($kid);

        return true;
    });
})->with([
    'unknown key id' => [fn (Signer $signer) => $signer->sign(manifestClaims(), 'mizuno-run-club-local'), 'unknown_kid', 'mizuno-run-club-local'],
    'bad signature' => [fn () => (new Signer('another-seed'))->sign(manifestClaims()), 'bad_signature', 'test-key-1'],
    'key id not a string' => [fn (Signer $signer) => $signer->signRaw('{"alg":"EdDSA","kid":1.5}', '{}'), 'malformed', null],
    'wrong shape' => [fn (Signer $signer) => $signer->sign(manifestClaims(['ver' => '4'])), 'malformed', 'test-key-1'],
    'other installation' => [fn (Signer $signer) => $signer->sign(manifestClaims(['aud' => 'someone-else'])), 'wrong_audience', 'test-key-1'],
    'other product' => [fn (Signer $signer) => $signer->sign(manifestClaims(['prd' => 'another-product'])), 'wrong_product', 'test-key-1'],
]);

it('does not announce anything for a genuine manifest', function () {
    Event::fake([AssertionRejected::class]);

    $this->verifier->verify($this->signer->sign(manifestClaims()));

    Event::assertNotDispatched(AssertionRejected::class);
});
