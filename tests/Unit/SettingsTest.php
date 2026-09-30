<?php

use PlinCode\PlatformAuthorizer\Exceptions\InvalidConfigurationException;
use PlinCode\PlatformAuthorizer\Settings;

function validConfig(array $overrides = []): array
{
    return [
        'url' => 'https://auth.example.test',
        'product' => 'mizuno-run-club',
        'installation' => 'mizuno-acme',
        'keys' => ['key-1' => base64_encode(str_repeat('a', 32))],
        'timeout' => 3,
        'livewire_grace_seconds' => 900,
        'protected_livewire_namespaces' => ['App\\Panel'],
        'except_routes' => ['panel.logout'],
        'global_scope' => '__global__',
        'guard' => null,
        'home' => '/platform',
        'denied_url' => '/admin',
        ...$overrides,
    ];
}

it('builds the settings from a valid configuration', function () {
    $settings = Settings::fromConfig(validConfig(['url' => 'https://auth.example.test/']));

    expect($settings->url)->toBe('https://auth.example.test')
        ->and($settings->product)->toBe('mizuno-run-club')
        ->and($settings->installation)->toBe('mizuno-acme')
        ->and($settings->keys)->toHaveKey('key-1')
        ->and($settings->timeout)->toBe(3)
        ->and($settings->livewireGraceSeconds)->toBe(900)
        ->and($settings->protectedLivewireNamespaces)->toBe(['App\\Panel'])
        ->and($settings->exceptRoutes)->toBe(['panel.logout'])
        ->and($settings->globalScope)->toBe('__global__')
        ->and($settings->guard)->toBeNull()
        ->and($settings->home)->toBe('/platform')
        ->and($settings->deniedUrl)->toBe('/admin');
});

it('ignores settings it does not know, so an application can add its own', function () {
    $settings = Settings::fromConfig(validConfig(['enforce' => true, 'something' => ['else']]));

    expect($settings->product)->toBe('mizuno-run-club');
});

it('accepts more than one public key so a rotation can overlap', function () {
    $settings = Settings::fromConfig(validConfig(['keys' => [
        'key-1' => base64_encode(str_repeat('a', 32)),
        'key-2' => base64_encode(str_repeat('b', 32)),
    ]]));

    expect(array_keys($settings->keys))->toBe(['key-1', 'key-2']);
});

it('allows plain http only towards loopback hosts', function (mixed $url, bool $valid) {
    $build = fn () => Settings::fromConfig(validConfig(['url' => $url]));

    $valid ? expect($build())->toBeInstanceOf(Settings::class) : expect($build)->toThrow(InvalidConfigurationException::class);
})->with([
    'https' => ['https://auth.example.test', true],
    'http on localhost' => ['http://localhost:8081', true],
    'http on loopback ip' => ['http://127.0.0.1:8081', true],
    'http on a public host' => ['http://auth.example.test', false],
    'no scheme' => ['auth.example.test', false],
    'credentials in the url' => ['https://user:secret@auth.example.test', false],
    'query string' => ['https://auth.example.test?x=1', false],
    'fragment' => ['https://auth.example.test#x', false],
    'empty' => ['', false],
    'not a string' => [['https://auth.example.test'], false],
]);

it('rejects every malformed setting', function (array $overrides, string $setting) {
    expect(fn () => Settings::fromConfig(validConfig($overrides)))
        ->toThrow(InvalidConfigurationException::class, $setting);
})->with([
    'missing product' => [['product' => null], 'product'],
    'empty product' => [['product' => ''], 'product'],
    'product not a string' => [['product' => 12], 'product'],
    'missing installation' => [['installation' => null], 'installation'],
    'installation with a space' => [['installation' => 'a b'], 'installation'],
    'installation too long' => [['installation' => str_repeat('a', 101)], 'installation'],
    'no keys' => [['keys' => []], 'keys'],
    'keys not an array' => [['keys' => 'abc'], 'keys'],
    'key id not a string' => [['keys' => [0 => base64_encode(str_repeat('a', 32))]], 'keys'],
    'key not base64' => [['keys' => ['key-1' => '@@@']], 'keys'],
    'key of the wrong length' => [['keys' => ['key-1' => base64_encode('short')]], 'keys'],
    'key not a string' => [['keys' => ['key-1' => ['x']]], 'keys'],
    'timeout zero' => [['timeout' => 0], 'timeout'],
    'timeout too large' => [['timeout' => 11], 'timeout'],
    'timeout not an integer' => [['timeout' => '3'], 'timeout'],
    'negative grace' => [['livewire_grace_seconds' => -1], 'livewire_grace_seconds'],
    'grace over an hour' => [['livewire_grace_seconds' => 3601], 'livewire_grace_seconds'],
    'namespaces not a list of strings' => [['protected_livewire_namespaces' => [1]], 'protected_livewire_namespaces'],
    'routes not a list of strings' => [['except_routes' => 'x'], 'except_routes'],
    'empty global scope' => [['global_scope' => ''], 'global_scope'],
    'guard not a string' => [['guard' => 5], 'guard'],
    'home without a slash' => [['home' => 'platform'], 'home'],
    'home leaving the site' => [['home' => '//evil.example.test'], 'home'],
    'home not a string' => [['home' => null], 'home'],
    'denied url with a scheme that is not http' => [['denied_url' => 'javascript:alert(1)'], 'denied_url'],
    'denied url empty' => [['denied_url' => ''], 'denied_url'],
]);

it('never echoes a key in the error message', function () {
    try {
        Settings::fromConfig(validConfig(['keys' => ['key-1' => 'not-a-real-key-value']]));
    } catch (InvalidConfigurationException $exception) {
        expect($exception->getMessage())->not->toContain('not-a-real-key-value');

        return;
    }

    $this->fail('The configuration should have been refused.');
});

it('reads the settings from the application configuration', function () {
    config()->set('platform-authorizer', validConfig(['product' => 'from-config']));

    expect(app(Settings::class)->product)->toBe('from-config');
});
