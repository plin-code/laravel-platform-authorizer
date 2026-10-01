# Testing your application

[Back to the README](../README.md)

`PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer` replaces the authorizer in the tests of an application. It refuses to run anywhere but in a test run (it throws a `LogicException` otherwise), so its well known test key can never open a real installation.

```php
use PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer;

$authorizer = FakeAuthorizer::install();     // trusts the test key, fakes the HTTP endpoints
$authorizer->grant($user);                   // a valid authorization in the session, for this user
$authorizer->setFlags(['check-in' => true]); // a known flag state, no authorization needed
```

* `install(): self` adds the test public key to `keys` and fakes the `/v1/flags` endpoints of the configured `url` with `Http::fake()`. Call it once the configuration of the application is in place (`url`, `product`, `installation`).
* `grant(?Authenticatable $user = null, array $claims = [], ?Signer $signer = null): string` puts an assertion in the session and returns it. Without a user the assertion carries `vendor@example.test`. Override claims or the signer to build one that must be refused.
* `setFlags(array $flags): void` stores a manifest with these flags, as if the authorizer had signed them.
* `flags(): array`, `version(): int` and `writes(): int` describe what the fake authorizer did: the flags of the latest manifest, its version (0 when none) and how many flag writes it accepted.
* `signer(): Signer` gives access to the test key pair for anything else that needs a signed token.

The fake answers flag writes with the same rules as the real service for the bearer: a missing or invalid assertion gets `401 invalid`, an expired one `401 expired`. It does not model revoked identities or deactivated installations.

A test that checks a refusal:

```php
$authorizer = FakeAuthorizer::install();
$authorizer->grant($user, ['aud' => 'another-installation']);

$this->actingAs($user)->get('/platform')->assertForbidden();
```

See [Protocol](protocol.md) for the claims that can be overridden.
