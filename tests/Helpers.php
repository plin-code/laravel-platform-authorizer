<?php

use PlinCode\PlatformAuthorizer\Settings;
use PlinCode\PlatformAuthorizer\Testing\Signer;

const TEST_NONCE = 'aabbccddeeff00112233445566778899aabbccddeeff00112233445566778899';

/**
 * A configuration that trusts the given signer's public key.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function configFor(Signer $signer, array $overrides = []): array
{
    return [
        'url' => 'https://auth.example.test',
        'product' => 'mizuno-run-club',
        'installation' => 'mizuno-acme',
        'keys' => [$signer->keyId() => $signer->publicKey()],
        'timeout' => 3,
        'livewire_grace_seconds' => 900,
        'protected_livewire_namespaces' => [],
        'except_routes' => [],
        'global_scope' => '__global__',
        'guard' => null,
        'home' => '/',
        'denied_url' => '/',
        'route_prefix' => 'platform-authorizer',
        'middleware' => ['web', 'auth'],
        ...$overrides,
    ];
}

/**
 * Settings that trust the given signer's public key.
 *
 * @param  array<string, mixed>  $overrides
 */
function settingsFor(Signer $signer, array $overrides = []): Settings
{
    return Settings::fromConfig(configFor($signer, $overrides));
}

/**
 * The claims of a valid assertion for the settings above.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function assertionClaims(array $overrides = []): array
{
    return [
        'iss' => 'https://auth.example.test',
        'aud' => 'mizuno-acme',
        'prd' => 'mizuno-run-club',
        'sub' => 'github:1001',
        'email' => 'vendor@example.test',
        'nonce' => TEST_NONCE,
        'iat' => now()->getTimestamp() - 10,
        'exp' => now()->getTimestamp() + 3600,
        'jti' => 'jti-test',
        ...$overrides,
    ];
}

/**
 * The claims of a valid manifest for the settings above.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function manifestClaims(array $overrides = []): array
{
    return [
        'aud' => 'mizuno-acme',
        'prd' => 'mizuno-run-club',
        'ver' => 4,
        'iat' => 1_790_000_000,
        'flags' => ['check-in' => true, 'custom-fields.management' => false],
        ...$overrides,
    ];
}
