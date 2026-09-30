<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\Manifests\ManifestVerifier;
use PlinCode\PlatformAuthorizer\Manifests\StoreOutcome;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    $this->signer = new Signer;
    $settings = settingsFor($this->signer);
    $this->repository = new ManifestRepository(new ManifestVerifier($settings), $settings);
});

function storedToken(): ?string
{
    return DB::table('feature_manifests')->where('installation', 'mizuno-acme')->value('token');
}

it('has nothing to read before a manifest is stored', function () {
    expect($this->repository->current())->toBeNull();
});

it('stores a genuine manifest and reads it back', function () {
    $token = $this->signer->sign(manifestClaims(['ver' => 3]));

    expect($this->repository->store($token))->toBe(StoreOutcome::Stored)
        ->and(storedToken())->toBe($token)
        ->and($this->repository->current()?->version)->toBe(3)
        ->and($this->repository->current()?->flags)->toBe(['check-in' => true, 'custom-fields.management' => false]);
});

it('stores and reads back a manifest with a flag named with digits', function () {
    $claims = json_encode(array_diff_key(manifestClaims(['ver' => 3]), ['flags' => true]), JSON_THROW_ON_ERROR);
    $token = $this->signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', substr($claims, 0, -1).',"flags":{"123":true,"check-in":false}}');

    expect($this->repository->store($token))->toBe(StoreOutcome::Stored)
        ->and($this->repository->current()?->flags)->toBe(['123' => true, 'check-in' => false]);
});

it('refuses to store a manifest whose flags are a json list', function () {
    $claims = json_encode(array_diff_key(manifestClaims(['ver' => 3]), ['flags' => true]), JSON_THROW_ON_ERROR);
    $token = $this->signer->signRaw('{"alg":"EdDSA","kid":"test-key-1"}', substr($claims, 0, -1).',"flags":[]}');

    expect($this->repository->store($token))->toBe(StoreOutcome::Invalid)
        ->and($this->repository->current())->toBeNull();
});

it('keeps a single row per installation', function () {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 1])));
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 2])));

    expect(DB::table('feature_manifests')->count())->toBe(1)
        ->and($this->repository->current()?->version)->toBe(2);
});

it('refuses a token that is not a genuine manifest for this installation', function (Closure $build) {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 1])));
    $before = storedToken();

    expect($this->repository->store($build($this->signer)))->toBe(StoreOutcome::Invalid)
        ->and(storedToken())->toBe($before);
})->with([
    'garbage' => [fn () => 'garbage'],
    'other installation' => [fn (Signer $signer) => $signer->sign(manifestClaims(['aud' => 'someone-else', 'ver' => 9]))],
    'other key' => [fn () => (new Signer('another-seed'))->sign(manifestClaims(['ver' => 9]))],
    'wrong types' => [fn (Signer $signer) => $signer->sign(manifestClaims(['ver' => '9']))],
]);

it('refuses an older version and accepts the same or a newer one', function (int $incoming, StoreOutcome $outcome, int $expectedVersion) {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 5])));

    expect($this->repository->store($this->signer->sign(manifestClaims(['ver' => $incoming]))))->toBe($outcome)
        ->and($this->repository->current()?->version)->toBe($expectedVersion);
})->with([
    'older' => [4, StoreOutcome::Older, 5],
    'much older' => [1, StoreOutcome::Older, 5],
    'same' => [5, StoreOutcome::Stored, 5],
    'newer' => [6, StoreOutcome::Stored, 6],
]);

it('ignores a manifest row that was edited by hand', function () {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 2])));
    [$header, , $signature] = explode('.', (string) storedToken());
    $forged = rtrim(strtr(base64_encode(json_encode(manifestClaims(['ver' => 9, 'flags' => ['check-in' => true]]))), '+/', '-_'), '=');
    DB::table('feature_manifests')->update(['token' => $header.'.'.$forged.'.'.$signature]);

    Log::spy();

    expect($this->repository->current())->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['installation' => 'mizuno-acme', 'reason' => 'invalid'],
    );
});

it('ignores a row written for another installation', function () {
    DB::table('feature_manifests')->insert([
        'installation' => 'someone-else',
        'token' => $this->signer->sign(manifestClaims(['aud' => 'someone-else'])),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($this->repository->current())->toBeNull();
});

it('falls back to nothing, and says so, when the table is missing', function () {
    Schema::drop('feature_manifests');
    Log::spy();

    expect($this->repository->current())->toBeNull();

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn (string $message, array $context): bool => $context === ['installation' => 'mizuno-acme', 'reason' => 'unreadable'],
    );
});
