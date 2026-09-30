<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use PlinCode\PlatformAuthorizer\Manifests\ManifestRepository;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer));
    $this->repository = app(ManifestRepository::class);
});

function authorizerHolds(Signer $signer, int $version, array $flags = ['check-in' => true]): string
{
    $token = $signer->sign(manifestClaims(['ver' => $version, 'flags' => $flags]));
    Http::fake(['auth.example.test/v1/flags/mizuno-acme' => Http::response(['manifest' => $token])]);

    return $token;
}

it('downloads the latest manifest and keeps it', function () {
    $token = authorizerHolds($this->signer, 3);

    $this->artisan('platform-authorizer:sync-flags')
        ->expectsOutputToContain('version 3')
        ->doesntExpectOutputToContain($token)
        ->assertExitCode(0);

    expect($this->repository->current()?->version)->toBe(3)
        ->and($this->repository->current()?->flags)->toBe(['check-in' => true]);
});

it('accepts the same version again and a newer one', function (int $stored, int $incoming) {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => $stored, 'flags' => []])));
    authorizerHolds($this->signer, $incoming);

    $this->artisan('platform-authorizer:sync-flags')->assertExitCode(0);

    expect($this->repository->current()?->version)->toBe($incoming);
})->with([[4, 4], [4, 5]]);

it('refuses a version older than the stored one and says so', function () {
    $this->repository->store($this->signer->sign(manifestClaims(['ver' => 6, 'flags' => ['check-in' => false]])));
    authorizerHolds($this->signer, 5);

    $this->artisan('platform-authorizer:sync-flags')
        ->expectsOutputToContain('older')
        ->assertExitCode(1);

    expect($this->repository->current()?->version)->toBe(6)
        ->and($this->repository->current()?->flags)->toBe(['check-in' => false]);
});

it('refuses a manifest it cannot trust', function () {
    Http::fake(['*' => Http::response(['manifest' => (new Signer('another-seed'))->sign(manifestClaims())])]);

    $this->artisan('platform-authorizer:sync-flags')->expectsOutputToContain('not accepted')->assertExitCode(1);

    expect(DB::table('feature_manifests')->count())->toBe(0);
});

it('is content when the authorizer has issued no manifest yet', function () {
    Http::fake(['*' => Http::response(['error' => 'not_found'], 404)]);

    $this->artisan('platform-authorizer:sync-flags')->expectsOutputToContain('No manifest')->assertExitCode(0);
});

it('fails, without a stack trace, when the authorizer is not available', function () {
    Http::fake(['*' => Http::response('boom', 500)]);

    $this->artisan('platform-authorizer:sync-flags')->expectsOutputToContain('not available')->assertExitCode(1);
});

it('fails, naming the setting, when the configuration cannot be used', function () {
    config()->set('platform-authorizer.keys', []);

    $this->artisan('platform-authorizer:sync-flags')->expectsOutputToContain('[keys]')->assertExitCode(1);
});

it('is scheduled every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($event): bool => str_contains((string) $event->command, 'platform-authorizer:sync-flags'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 * * * *');
});
