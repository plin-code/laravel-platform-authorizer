<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Livewire\Mechanisms\HandleComponents\Checksum;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use PlinCode\PlatformAuthorizer\PlatformAuthorization;
use PlinCode\PlatformAuthorizer\Testing\Signer;

beforeEach(function () {
    Carbon::setTestNow(Carbon::createFromTimestamp(1_790_000_000));
    $this->signer = new Signer;
    config()->set('platform-authorizer', configFor($this->signer, [
        'protected_livewire_namespaces' => ['PlinCode\\PlatformAuthorizer\\Tests\\Fixtures'],
    ]));
    $this->user = new GenericUser(['id' => 1, 'email' => 'vendor@example.test']);

    Route::middleware('web')->get('/other', fn () => Blade::render('<html><body><livewire:other-panel /></body></html>'));
});

afterEach(fn () => Carbon::setTestNow());

function assertionExpiredAgo(int $seconds): array
{
    return [PlatformAuthorization::SESSION_ASSERTION => test()->signer->sign(assertionClaims(['exp' => now()->getTimestamp() - $seconds]))];
}

/**
 * A snapshot of the given component with a valid checksum, as somebody who
 * knows the application key can make it, claiming to come from a page that
 * matches no route. Livewire picks the middleware to replay from that path
 * and replays none for a path it cannot match.
 *
 * @return array<string, mixed>
 */
function forgedSnapshot(string $component): array
{
    $page = test()->actingAs(test()->user)
        ->withSession(assertionExpiredAgo(-3600))
        ->get($component === 'probe-panel' ? '/probe' : '/other')
        ->assertOk();

    preg_match('/wire:snapshot="([^"]+)"/', (string) $page->getContent(), $matches);
    $snapshot = json_decode(html_entity_decode($matches[1], ENT_QUOTES), true);

    unset($snapshot['checksum']);
    $snapshot['memo']['path'] = '/nonexistent';
    $snapshot['checksum'] = Checksum::generate($snapshot);

    return $snapshot;
}

function updateWith(array $snapshot, array $session): TestResponse
{
    test()->flushSession();

    return test()->actingAs(test()->user)
        ->withSession($session)
        ->withHeaders(['X-Livewire' => '1'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), [
            '_token' => csrf_token(),
            'components' => [['snapshot' => json_encode($snapshot), 'updates' => new stdClass, 'calls' => []]],
        ]);
}

it('refuses a forged snapshot of a protected component when there is no authorization', function () {
    updateWith(forgedSnapshot('probe-panel'), [])->assertForbidden();
});

it('refuses a forged snapshot of a protected component when the authorization is not valid', function () {
    updateWith(forgedSnapshot('probe-panel'), [PlatformAuthorization::SESSION_ASSERTION => (new Signer('another-seed'))->sign(assertionClaims())])
        ->assertForbidden();
});

it('follows the same rules as the middleware for a forged snapshot', function (int $secondsAgo, int $status) {
    updateWith(forgedSnapshot('probe-panel'), assertionExpiredAgo($secondsAgo))->assertStatus($status);
})->with([
    'valid' => [-3600, 200],
    'inside the grace' => [600, 200],
    'past the grace' => [901, 419],
]);

it('answers a forged snapshot 403 past the grace when the expired status is 403', function () {
    config()->set('platform-authorizer.expired_status', 403);

    updateWith(forgedSnapshot('probe-panel'), assertionExpiredAgo(901))->assertForbidden();
});

it('keeps serving a forged snapshot inside the grace when the expired status is 403', function () {
    config()->set('platform-authorizer.expired_status', 403);

    updateWith(forgedSnapshot('probe-panel'), assertionExpiredAgo(600))->assertOk();
});

it('leaves a component outside the protected namespaces alone', function () {
    updateWith(forgedSnapshot('other-panel'), [])->assertOk();
});

it('matches whole namespaces only', function () {
    config()->set('platform-authorizer.protected_livewire_namespaces', ['PlinCode\\PlatformAuthorizer\\Tests\\Fix']);

    updateWith(forgedSnapshot('probe-panel'), [])->assertOk();
});

it('accepts a namespace written with a trailing backslash', function () {
    config()->set('platform-authorizer.protected_livewire_namespaces', ['PlinCode\\PlatformAuthorizer\\Tests\\Fixtures\\']);

    updateWith(forgedSnapshot('probe-panel'), [])->assertForbidden();
});

it('still protects the components when the rest of the configuration is unusable', function () {
    $snapshot = forgedSnapshot('probe-panel');
    config()->set('platform-authorizer.keys', []);

    updateWith($snapshot, assertionExpiredAgo(-3600))->assertForbidden();
});

it('protects nothing when no namespace is configured', function () {
    config()->set('platform-authorizer.protected_livewire_namespaces', []);

    updateWith(forgedSnapshot('probe-panel'), [])->assertOk();
});
