# Laravel Platform Authorizer

Remote authorization for the vendor panel of a self-hosted Laravel application, and feature flags that only the vendor can change.

The application never holds a secret. Everything that decides who gets in, and which flags apply, is signed by a service run by the vendor (the authorizer), and the application only verifies signatures with public keys committed in its configuration.

## What it does

* A panel or a group of routes opens only for a user who has completed a round trip through the authorizer. The round trip ends with a signed assertion that names the installation, the product and the email of the logged in user.
* The assertion is kept in the session as it is, and it is verified again on every request, so a session row written by hand grants nothing.
* Livewire components can be protected as well, including requests that try to hide which page they come from.
* Feature flags are read from a signed manifest through a Pennant driver. A row edited in the database has no effect, and changing a flag needs an authorization from the authorizer.
* Every failure closes the door: an unreachable authorizer, a bad signature, a wrong nonce or an unauthorized identity all end in a generic refusal.

## Requirements

* PHP 8.3 or later with `ext-sodium`
* Laravel 12 or 13
* Optional: `laravel/pennant` for the flag driver, `livewire/livewire` for the component checks

## Installation

```bash
composer require plin-code/laravel-platform-authorizer
php artisan vendor:publish --tag=platform-authorizer-config
php artisan vendor:publish --tag=platform-authorizer-migrations
php artisan migrate
```

## Configuration

`config/platform-authorizer.php` belongs in version control. Every value except the installation slug is written in the file, not read from the environment: a value that decides who gets in must not be one line of `.env` away from being changed.

| Key | Meaning |
| --- | --- |
| `url` | Base URL of the authorizer. Must be https (http is accepted towards localhost only). It is also the expected issuer of every assertion. |
| `product` | Slug of the product. |
| `installation` | Slug of this installation, read from `PLATFORM_INSTALLATION`. |
| `keys` | Public keys of the authorizer, `key id => base64 Ed25519 public key`. More than one entry is allowed, see key rotation. |
| `timeout` | Seconds to wait for the authorizer, 3 by default. Nothing is retried. |
| `livewire_grace_seconds` | How long after its expiry an assertion still serves Livewire requests already in flight, 900 by default. |
| `expired_status` | Status answered to a request whose authorization has expired, 419 by default. Only 403 and 419 are accepted, see Livewire. |
| `protected_livewire_namespaces` | Livewire components under these namespaces refuse to hydrate without a valid authorization. |
| `except_routes` | Route names the middleware lets through, for instance a logout route. |
| `global_scope` | The Pennant scope of global flags, `__global__` by default. |
| `guard` | Guard whose user is compared with the assertion, the default guard when null. |
| `home` | Where to go after a successful authorization when no intended URL is stored. |
| `denied_url` | Target of the link on the refusal page. |
| `route_prefix`, `middleware` | Prefix and middleware of the two routes of the round trip. |

Unknown keys are ignored, so an application can keep its own settings in the same file. The configuration is validated when it is first used. An invalid value fails closed and the error names the setting, never its value.

## Protecting routes

The package registers two routes under the prefix: `platform-authorizer.redirect` starts the round trip and `platform-authorizer.callback` completes it. Put the middleware on whatever must be protected:

```php
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;

Route::middleware(['auth', RequirePlatformAuthorization::class])->group(function () {
    // ...
});
```

* A navigation without a valid assertion is redirected to the authorizer, and comes back where it was going.
* A request whose assertion is present but not valid (bad signature, another installation, another product, another user, no user) is refused with a 403 and the assertion is forgotten.
* The check for the current user is part of the verification: an assertion issued for another email never opens the door.

To ask the same question elsewhere, for instance in a gate:

```php
use PlinCode\PlatformAuthorizer\Facades\PlatformAuthorization;

Gate::define('viewHorizon', fn ($user = null): bool => $user !== null && PlatformAuthorization::isGranted());
```

## Livewire

A Livewire request cannot follow a redirect to another domain, so the middleware answers it differently:

* no assertion, or an assertion that is not valid: 403;
* an expired assertion: served for `livewire_grace_seconds` after the expiry, then the status of `expired_status`, 419 by default.

The two values of `expired_status` differ in what Livewire does with them. A 419 makes Livewire ask the user with its native confirm dialog (a notice that the page has expired) and reload the page only if the user accepts. The dialog appears once per page load, and the reload goes through the authorizer again. A 403 makes Livewire show its error modal with the error page inside, and the only way out is to close it and reload the page by hand. Keep 419 unless the modal is what you want. Any other value is refused when the configuration is validated.

The grace applies only to the real Livewire update endpoint. A plain request that carries the `X-Livewire` header gets none.

Livewire replays the middleware of the page a component was rendered on, and the page is named by the component snapshot. Set `protected_livewire_namespaces` to the namespaces of the components of the protected area and they also check the authorization when they hydrate, whatever the snapshot says.

## Feature flags

Register the driver as the Pennant store, without reading the name from the environment:

```php
// config/pennant.php
'default' => 'platform-authorizer',

'stores' => [
    'platform-authorizer' => ['driver' => 'platform-authorizer'],
],
```

* Reading verifies the signature of the stored manifest once per request. A missing table, a missing manifest, a bad signature, another installation or product, a key that is no longer configured or a malformed field all give the default declared by the flag.
* Writing (`activate`, `deactivate`, `activateForEveryone`, `forget`, `purge`) sends the complete flag set to the authorizer, with the assertion of the session as bearer, and stores the signed manifest it returns. Without an assertion the write is refused. An expired assertion answers with the same `expired_status` as a Livewire request, 419 by default.
* Flags are global. A write for any other scope is refused.
* A flag that must not be off by accident should not default to `true` if it protects something licensed: the defaults are reachable by whoever owns the database.

`php artisan platform-authorizer:sync-flags` downloads the latest manifest and refuses a version older than the stored one. It runs every hour on its own when the scheduler is running.

## Key rotation

The `keys` setting accepts several public keys, so a new key can be distributed before the authorizer starts signing with it.

1. The vendor generates the new key on the authorizer. It does not sign anything yet.
2. Add the new public key to `keys`, next to the old one, and deploy every installation.
3. The vendor activates the new key. From now on assertions and manifests are signed with it, and the old key only verifies.
4. Keep the old public key until every installation holds a manifest signed with the new key (a flag write or the hourly synchronisation does it) and the assertions signed with the old key have expired, which takes one hour.
5. Remove the old public key and deploy.

A manifest already stored stays signed by the key that issued it. Removing the old key too early makes the flags fall back to their defaults until the next synchronisation.

## Events

`PlinCode\PlatformAuthorizer\Events\AssertionRejected` is dispatched whenever an assertion or a manifest is refused. It carries `subject` (`assertion` or `manifest`), `reason` (`malformed`, `unknown_kid`, `bad_signature`, `wrong_issuer`, `wrong_audience`, `wrong_product`, `wrong_nonce`, `email_mismatch` or `expired`) and `kid`, the key id the token declared when it is a short plain string. It never carries a token, an email or a claim.

`PlinCode\PlatformAuthorizer\Client\AuthorizerClient::reportTamper(string $reason): bool` sends a report to the authorizer. It never throws.

## Testing your application

`PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer` replaces the authorizer in the tests of an application. It refuses to run anywhere but in a test run.

```php
use PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer;

$authorizer = FakeAuthorizer::install();   // trusts the test key, fakes the HTTP endpoints
$authorizer->grant($user);                 // a valid authorization in the session, for this user
$authorizer->setFlags(['check-in' => true]); // a known flag state, no authorization needed
```

* `grant(?Authenticatable $user = null, array $claims = [], ?Signer $signer = null): string` puts an assertion in the session. Override claims or the signer to build one that must be refused.
* `setFlags(array $flags): void`, `flags(): array`, `version(): int`, `writes(): int` and `events(): array` describe what the fake authorizer did.
* `signer(): Signer` gives access to the test key pair for anything else that needs a signed token.

## Limits

* Whoever can modify the source of the application can remove any check. The purpose is that doing so needs a change to the source, which is visible and overwritten by the next deploy.
* Somebody who controls the machine can copy an open session while it lasts, up to one hour after the assertion was issued (one hour and fifteen minutes for Livewire requests served during the grace).
* With outbound traffic blocked, an installation keeps the manifest it has.

## License

MIT. See `LICENSE.md`.
