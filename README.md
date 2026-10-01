# Laravel Platform Authorizer

<p align="center">
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/v/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/php-v/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://badge.laravel.cloud/badge/plin-code/laravel-platform-authorizer?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/plin-code/laravel-platform-authorizer/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/plin-code/laravel-platform-authorizer/run-tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/dt/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Remote authorization for the vendor panel of a self-hosted Laravel application, and feature flags that only the vendor can change.

## What it is

You sell a Laravel application that each customer runs on their own server. You (the vendor) need an area inside it, for instance a panel that switches licensed features on and off, that the customer's staff cannot open even though they own the server, the database and the `.env` file.

This package closes that area behind a round trip to an **authorizer**, a small service run by the software vendor (the Go service `plincode-authorizer`). A vendor user logs in on the authorizer, confirms which installation they are opening, and comes back with a signed **assertion**. The feature flags of the installation live in a **manifest** that the same authorizer signs.

The trust model in a few lines:

* The authorizer holds the private Ed25519 keys. The application only holds the matching public keys, committed in its configuration. **The application never holds a secret**, so there is nothing on the customer's server that can be used to forge an authorization.
* The assertion is kept in the session as it is and verified again on every request. A session row written by hand grants nothing.
* The manifest is stored in the database but verified on every read. A row edited by hand has no effect, and a flag can only be changed by asking the authorizer to sign a new manifest, which needs a valid assertion.
* Every failure closes the door: an unreachable authorizer, a bad signature, a wrong nonce or an identity that is not authorised all end in a generic refusal.

What it does not stop is someone who edits the source code of the application. See [Limits](#limits).

## Requirements

* PHP 8.3 or later with `ext-sodium`
* Laravel 12 or 13
* `laravel/pennant` for the flag driver (optional)
* `livewire/livewire` for the component checks (optional)

## What you need from the vendor

Before installing, ask the vendor (whoever runs the authorizer) for:

* **The authorizer URL**, for instance `https://authorizer.example.com`. It is both the address the package calls and the issuer written in every assertion.
* **The product slug**, for instance `acme-crm`.
* **The installation slug** of this deployment, for instance `acme-crm-rossi`. Each customer server has its own.
* **The public key or keys**: for each one a key id and a base64 encoded Ed25519 public key, for instance `acme-2026-1` and `G1yHmSCN25mB4Cp3WRutDKtJLT5ZXQdTGOAPmLeAA28=`.

The vendor needs from you:

* **The callback URL** of the installation, to register it on the authorizer. It is `https://<your app>/<route_prefix>/callback`, that is `https://<your app>/platform-authorizer/callback` with the default `route_prefix`. The authorizer only ever sends the browser back to this registered URL.
* **The email of every local user who will open the panel.** Each vendor user is registered on the authorizer with an email, and the assertion carries it. The package lets the request through only when that email matches the email of the user logged in to the application (the comparison ignores case). A vendor user therefore needs a local account with the same email.

## Quickstart

### 1. Install

```bash
composer require plin-code/laravel-platform-authorizer
php artisan vendor:publish --tag=platform-authorizer-config
php artisan vendor:publish --tag=platform-authorizer-migrations
php artisan migrate
```

The migration creates the `feature_manifests` table, which keeps the signed manifest of the installation.

### 2. Configure

`config/platform-authorizer.php` belongs in version control. Every value except the installation slug is written in the file and not read from the environment: a value that decides who gets in must not be one line of `.env` away from being changed.

```php
<?php

return [
    'url' => 'https://authorizer.example.com',

    'product' => 'acme-crm',

    'installation' => env('PLATFORM_INSTALLATION'),

    'keys' => [
        'acme-2026-1' => 'G1yHmSCN25mB4Cp3WRutDKtJLT5ZXQdTGOAPmLeAA28=',
    ],

    'timeout' => 3,

    'livewire_grace_seconds' => 900,

    'expired_status' => 419,

    'protected_livewire_namespaces' => [
        'App\\Livewire\\Platform',
    ],

    'except_routes' => [
        'logout',
    ],

    'global_scope' => '__global__',

    'guard' => null,

    'home' => '/platform',
    'denied_url' => '/',

    'route_prefix' => 'platform-authorizer',
    'middleware' => ['web', 'auth'],
];
```

Then set the slug of this installation in `.env`:

```dotenv
PLATFORM_INSTALLATION=acme-crm-rossi
```

| Key | Meaning |
| --- | --- |
| `url` | Base URL of the authorizer. Must be https (http is accepted towards `localhost`, `127.0.0.1` and `[::1]` only). It is also the expected issuer of every assertion, see [gotchas](#requirements-and-gotchas). |
| `product` | Slug of the product. |
| `installation` | Slug of this installation, read from `PLATFORM_INSTALLATION`. |
| `keys` | Public keys of the authorizer, `key id => base64 Ed25519 public key`. More than one entry is allowed, see [key rotation](#key-rotation). |
| `timeout` | Seconds to wait for the authorizer, between 1 and 10, 3 by default. Nothing is retried. |
| `livewire_grace_seconds` | How long after its expiry an assertion still serves Livewire requests already in flight, 900 by default. |
| `expired_status` | Status answered to a request whose authorization has expired, 419 by default. Only 403 and 419 are accepted, see [Livewire](#livewire). |
| `protected_livewire_namespaces` | Livewire components under these namespaces refuse to hydrate without a valid authorization. |
| `except_routes` | Route names the middleware lets through, for instance a logout route. |
| `global_scope` | The Pennant scope of the flags, `__global__` by default. |
| `guard` | Guard whose user is compared with the assertion, the default guard when null. |
| `home` | Where to go after a successful authorization when no intended URL is stored. |
| `denied_url` | Target of the link on the refusal page. |
| `route_prefix`, `middleware` | Prefix and middleware of the two routes of the round trip. |

Unknown keys are ignored, so an application can keep its own settings in the same file. The configuration is validated when it is first used. An invalid value fails closed, and the error names the setting, never its value.

### 3. Protect routes

```php
use App\Livewire\Platform\PlatformPanel;
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;

Route::middleware(['auth', RequirePlatformAuthorization::class])
    ->prefix('platform')
    ->group(function () {
        Route::get('/', PlatformPanel::class)->name('platform');
    });
```

Put `auth` **before** `RequirePlatformAuthorization`. The assertion is bound to the email of the logged in user, so the check needs a user to compare it with. With `auth` first, a guest goes to the login page and the round trip starts only once there is a user. Without it, a guest who still holds an assertion in the session is refused with a 403, because there is no email to match.

What the middleware does:

* A navigation without an assertion, or with an expired one, is redirected to the authorizer and comes back where it was going.
* A request whose assertion is present but not valid (bad signature, another installation, another product, another user) is refused with a 403 and the assertion is forgotten.
* A Livewire request is never redirected, see [Livewire](#livewire).

To ask the same question elsewhere, for instance in a gate:

```php
use PlinCode\PlatformAuthorizer\Facades\PlatformAuthorization;

Gate::define('viewHorizon', fn ($user = null): bool => $user !== null && PlatformAuthorization::isGranted());
```

The package registers two routes under `route_prefix`: `platform-authorizer.redirect` starts the round trip and `platform-authorizer.callback` completes it.

### 4. Register the Pennant store

Install Pennant if the application does not have it yet (`composer require laravel/pennant`, then publish its configuration). The driver replaces Pennant's own storage, so Pennant's `features` table is not used. Write the store name in the file, without reading it from the environment:

```php
// config/pennant.php
'default' => 'platform-authorizer',

'stores' => [
    'platform-authorizer' => ['driver' => 'platform-authorizer'],
],
```

### 5. Define flags

Define each flag with its default, the value used while no trustworthy manifest mentions it:

```php
// app/Providers/AppServiceProvider.php
use Laravel\Pennant\Feature;

public function boot(): void
{
    Feature::define('check-in', fn () => false);
    Feature::define('custom-fields', fn () => false);
}
```

The defaults are reachable by whoever owns the database (deleting the manifest is enough), so a flag that unlocks something licensed should default to `false`.

### 6. Read flags

Flags are global: every scope gets the value in the manifest. Any of these works:

```php
use Laravel\Pennant\Feature;

Feature::active('check-in');
Feature::for(config('platform-authorizer.global_scope'))->active('check-in');
```

```blade
@feature('check-in')
    ...
@endfeature
```

The manifest is read and verified once per request, however many flags are asked for.

### 7. Write flags

Writes go through the authorizer: the package sends the complete flag set with the assertion of the session as bearer, and stores the signed manifest that comes back. They only work in a request that holds an assertion, that is inside the protected area.

```php
use Laravel\Pennant\Feature;

$flags = Feature::for(config('platform-authorizer.global_scope'));

$flags->activate('check-in');
$flags->deactivate('custom-fields');
```

`Feature::activateForEveryone()`, `Feature::deactivateForEveryone()` and `Feature::purge()` work too. A write for any scope other than `global_scope` throws `UnsupportedScopeException`, so `Feature::activate('check-in')`, which uses the logged in user as scope, is refused.

A write can throw:

* `AuthorizationRejectedException` (403): no assertion in the session, or the authorizer refused it (identity revoked, installation deactivated).
* `AuthorizationExpiredException` (`expired_status`, 419 by default): the assertion has expired.
* `AuthorizerUnavailableException` (503): the authorizer is unreachable, too slow, rate limiting or answering something that cannot be trusted.

All three are HTTP exceptions, so Laravel renders them as error pages. Their messages come from the package translations.

### 8. A minimal Livewire panel

```php
<?php

namespace App\Livewire\Platform;

use Laravel\Pennant\Feature;
use Livewire\Component;

class PlatformPanel extends Component
{
    public function toggle(string $flag): void
    {
        $flags = Feature::for(config('platform-authorizer.global_scope'));

        $flags->active($flag) ? $flags->deactivate($flag) : $flags->activate($flag);
    }

    public function render()
    {
        $flags = Feature::for(config('platform-authorizer.global_scope'));

        return view('livewire.platform.panel', [
            'checkIn' => $flags->active('check-in'),
            'customFields' => $flags->active('custom-fields'),
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/platform/panel.blade.php --}}
<div>
    <label>
        <input type="checkbox" wire:click="toggle('check-in')" @checked($checkIn)>
        Check-in
    </label>
    <label>
        <input type="checkbox" wire:click="toggle('custom-fields')" @checked($customFields)>
        Custom fields
    </label>
</div>
```

The component lives under `App\Livewire\Platform`, which is listed in `protected_livewire_namespaces`, and its route is behind the middleware of step 3.

### 9. Keep flags in sync

The package schedules `platform-authorizer:sync-flags` every hour on its own. It downloads the latest manifest, so a flag changed on the authorizer reaches the installation without anyone opening the panel, and it refuses a version older than the stored one. All you need is the Laravel scheduler running, as usual:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Run it by hand after the first deploy, or whenever you want the latest flags at once:

```bash
php artisan platform-authorizer:sync-flags
```

Until the authorizer has issued a first manifest for the installation, the command says so and the flags keep their defaults.

## Requirements and gotchas

* **`SESSION_LIFETIME` must be at least 15 minutes.** The nonce of the round trip lives in the session. On the authorizer a login can take up to 10 minutes and the confirmation page lasts 5 more, so a shorter session can lose the nonce before the browser comes back (`missing_nonce`). Laravel's default of 120 minutes is fine.
* **Keep the session cookie `same_site` at `lax`** (Laravel's default). The browser comes back from the authorizer through a redirect started on another site, and a `strict` cookie is not sent with it.
* **`APP_LOCALE` drives the language** of the refusal page and of the exception messages. The package ships English and Italian. Other locales fall back to `APP_FALLBACK_LOCALE`.
* **`url` must use https.** Plain http is accepted only towards `localhost`, `127.0.0.1` and `[::1]`, for local testing.
* **`url` must be exactly the issuer of the authorizer** (its `AUTHORIZER_ISSUER`), without a trailing slash. The package compares the `iss` claim with `url`, so a different scheme, host, port or path refuses every assertion with `wrong_issuer`.
* **Each vendor user needs a local account with the same email** as on the authorizer, otherwise `email_mismatch`.
* **The callback URL registered on the authorizer must match `route_prefix`.** Changing the prefix means asking the vendor to register the new URL.

## Customising the refusal page and texts

When the authorizer does not let a user in, the package shows a page that says the area could not be opened, with a link to `denied_url`. It is the same for every cause: a page that told the reasons apart would help someone probing the check. The reason is in the log, see [Troubleshooting](#troubleshooting).

To change the page, publish the view:

```bash
php artisan vendor:publish --tag=platform-authorizer-views
```

and edit `resources/views/vendor/platform-authorizer/denied.blade.php`. The view receives `$link`, the validated `denied_url`.

To change the texts (the refusal page and the messages of the exceptions), publish the translations:

```bash
php artisan vendor:publish --tag=platform-authorizer-translations
```

and edit `lang/vendor/platform-authorizer/<locale>/messages.php`. Add a folder for another locale to support it. The keys live in the `platform-authorizer::messages` namespace.

## Livewire

A Livewire request cannot follow a redirect to another domain, so the middleware answers it differently:

* no assertion, or an assertion that is not valid: 403;
* an expired assertion: served for `livewire_grace_seconds` after the expiry, then the status of `expired_status`, 419 by default.

The two values of `expired_status` differ in what Livewire does with them. A 419 makes Livewire ask the user with its native confirm dialog (a notice that the page has expired) and reload the page only if the user accepts. The dialog appears once per page load, and the reload goes through the authorizer again. A 403 makes Livewire show its error modal with the error page inside, and the only way out is to close it and reload the page by hand. Keep 419 unless the modal is what you want. Any other value is refused when the configuration is validated.

The grace applies only to the real Livewire update endpoint. A plain request that carries the `X-Livewire` header gets none.

Livewire replays the middleware of the page a component was rendered on, and the page is named by the component snapshot. The snapshot is signed with the application key, which the owner of the server holds, so the page it names can be forged. Set `protected_livewire_namespaces` to the namespaces of the components of the protected area and they also check the authorization when they hydrate, whatever the snapshot says.

## Key rotation

The `keys` setting accepts several public keys, so a new key can be distributed before the authorizer starts signing with it.

1. The vendor generates the new key on the authorizer. It does not sign anything yet.
2. Add the new public key to `keys`, next to the old one, and deploy every installation.
3. The vendor activates the new key. From now on assertions and manifests are signed with it, and the old key only verifies.
4. Keep the old public key until every installation holds a manifest signed with the new key (a flag write does it, and so does the hourly synchronisation once the vendor has re-signed the manifest on the authorizer) and the assertions signed with the old key have expired, which takes one hour.
5. Remove the old public key and deploy.

A manifest already stored stays signed by the key that issued it. Removing the old key too early makes the flags fall back to their defaults until the next synchronisation, and the log says `unknown_kid`.

## Protocol

This is what the package exchanges with the authorizer. Every call has the configured timeout, none is retried and none follows a redirect.

### The round trip

1. The middleware stores a random nonce (64 lowercase hex characters) in the session and redirects the browser to

   ```
   GET <url>/v1/authorize?product=<product>&installation=<installation>&nonce=<nonce>
   ```

2. The authorizer checks the installation and the product, has the vendor user log in (with GitHub) and shows a confirmation page where they type the name of the installation.
3. The authorizer redirects the browser to the registered callback URL with one of

   ```
   GET /platform-authorizer/callback?assertion=<jws>
   GET /platform-authorizer/callback?error=access_denied
   ```

   `access_denied` covers a cancelled confirmation, an identity that is not authorised or revoked, a deactivated installation and any failure on the authorizer.
4. The callback takes the nonce out of the session (it serves one attempt, whatever happens), verifies the assertion against it and against the logged in user, keeps it in the session and redirects to the intended URL or to `home`.

### Flags

```
POST <url>/v1/flags
Authorization: Bearer <assertion>
Content-Type: application/json

{"installation": "<installation>", "flags": {"check-in": true, "custom-fields": false}}
```

The flag set is always the complete set, as a JSON object. The authorizer accepts the bearer until `exp` plus 30 seconds, and only while the installation is active and the identity is not revoked. It accepts up to 200 flags, with names of up to 100 characters. Answers:

| Status | Body | What the package does |
| --- | --- | --- |
| 200 | `{"manifest": "<jws>"}` | verifies and stores the manifest |
| 401 | `{"error": "expired"}` | throws `AuthorizationExpiredException` |
| 401 | `{"error": "invalid"}` | throws `AuthorizationRejectedException` |
| 403 | `{"error": "forbidden"}` | throws `AuthorizationRejectedException` |
| 400, 429, 5xx, 3xx, no answer | | throws `AuthorizerUnavailableException` |

```
GET <url>/v1/flags/<installation>
```

Answers 200 with `{"manifest": "<jws>"}`, or 404 with `{"error": "not_found"}` when no manifest has been issued yet. It needs no credentials: a manifest is not a secret, and it is only accepted when its signature verifies.

### Token format

Assertions and manifests are compact JWS tokens: `base64url(header).base64url(payload).base64url(signature)`, without padding. The header is `{"alg":"EdDSA","kid":"<key id>"}`, and `EdDSA` (Ed25519) is the only algorithm accepted. The signature covers the exact bytes `<header>.<payload>` as received. The `kid` selects the public key in `keys`.

Claims of an **assertion**, all required with exactly these types:

| Claim | Type | Meaning |
| --- | --- | --- |
| `iss` | string | the authorizer, must equal `url` |
| `aud` | string | the installation slug |
| `prd` | string | the product slug |
| `sub` | string | the vendor identity, `github:<numeric id>` |
| `email` | string | the email of the vendor user, compared with the local user |
| `nonce` | string | the nonce of the round trip |
| `iat` | integer | issued at, Unix seconds |
| `exp` | integer | expiry, one hour after `iat` |
| `jti` | string | unique id, also written in the authorizer's audit log |

The package tolerates 30 seconds of clock skew on `exp`.

Claims of a **manifest**, all required:

| Claim | Type | Meaning |
| --- | --- | --- |
| `aud` | string | the installation slug |
| `prd` | string | the product slug |
| `ver` | integer, at least 1 | version, grows with every write. A manifest with a lower version than the stored one is refused |
| `iat` | integer | issued at, Unix seconds |
| `flags` | object | flag name to boolean |

## Troubleshooting

The package logs short reason codes and setting names. It never logs a token, a nonce, an assertion, an email or a claim.

| Log message | Context | Meaning |
| --- | --- | --- |
| `Platform authorization refused` | `reason` | The callback refused what came back from the authorizer. See the reasons below. |
| `Platform request refused` | `reason` | A request to the protected area was refused. See the reasons below. |
| `Platform authorizer configuration is invalid` | `setting` | A value in `config/platform-authorizer.php` is not usable. The setting is named, never its value. |
| `Feature manifest missing, shipped defaults in use` | `installation`, `reason: missing` | There is no manifest for this installation yet. Run `platform-authorizer:sync-flags`. If the authorizer has none either, the first flag write creates it. Logged at most once per request. |
| `Feature manifest refused, shipped defaults in use` | `installation`, `reason` | The stored manifest did not verify. The reason is one of the codes below. |
| `Feature manifest unreadable, shipped defaults in use` | `installation`, `reason: unreadable` | The `feature_manifests` table cannot be read. Run the migration. |
| `Platform authorizer unreachable` | `endpoint` | No answer within `timeout`. |
| `Platform authorizer unavailable` | `endpoint`, `status` | The authorizer answered 400, 429, a 5xx or a redirect. |
| `Platform authorizer answered unexpectedly` | `endpoint`, `status` | The answer had no manifest in it. |
| `Platform authorizer refused a flag write` | `endpoint`, `status` | 401 or 403 on a flag write: the identity was revoked, the installation deactivated, or the assertion was not accepted. |
| `Platform authorizer answered with a manifest that was not accepted` | `outcome` | A flag write returned a manifest that does not verify (`invalid`) or is older than the stored one (`older`). |
| `Platform authorizer manifest not accepted during synchronisation` | `outcome` | The same, during `sync-flags`. |

Reasons:

| Reason | Meaning and usual cause |
| --- | --- |
| `refused_by_authorizer` | The authorizer answered `access_denied`: the vendor user cancelled, is not authorised for this installation, was revoked, or the installation is deactivated. |
| `missing_assertion` | The callback was opened without an assertion. |
| `missing_nonce` | The session lost the nonce of the round trip: a session shorter than 15 minutes, a session cookie that was not sent back (`same_site` set to `strict`), or the callback opened a second time. |
| `missing` | A Livewire request without any assertion in the session. |
| `configuration` | The configuration is invalid. The assertion in the session is kept. |
| `malformed` | Not a well formed token, or a claim missing or of the wrong type. |
| `unknown_kid` | The token was signed with a key that is not in `keys`. During a key rotation it means the new key was activated before it was deployed here, or the old key was removed too early. Otherwise ask the vendor for the current public key. |
| `bad_signature` | The signature does not match: the token or the stored manifest was altered, or the public key under that key id is not the right one. |
| `wrong_issuer` | `url` is not exactly the issuer of the authorizer. |
| `wrong_audience` | The token was issued for another installation. Check `PLATFORM_INSTALLATION`. |
| `wrong_product` | The token was issued for another product. Check `product`. |
| `wrong_nonce` | The assertion was issued for another round trip, for instance an older callback link opened while a new round trip was in progress. |
| `email_mismatch` | The email in the assertion is not the email of the logged in user. Give the vendor user a local account with the same email, or ask the vendor to fix the registered one. The assertion is forgotten. |
| `expired` | The assertion is more than one hour old. A navigation goes through the authorizer again, a Livewire request gets `expired_status` after the grace. |

The codes from `malformed` onwards are also carried by the `AssertionRejected` event.

## Events

`PlinCode\PlatformAuthorizer\Events\AssertionRejected` is dispatched whenever an assertion or a manifest is refused, so an application can listen to it, for instance to raise an alert on an unexpected key id. It carries `subject` (`assertion` or `manifest`), `reason` (`malformed`, `unknown_kid`, `bad_signature`, `wrong_issuer`, `wrong_audience`, `wrong_product`, `wrong_nonce`, `email_mismatch` or `expired`) and `kid`, the key id the token declared when it is a short plain string. It never carries a token, an email or a claim.

## Testing your application

`PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer` replaces the authorizer in the tests of an application. It refuses to run anywhere but in a test run.

```php
use PlinCode\PlatformAuthorizer\Testing\FakeAuthorizer;

$authorizer = FakeAuthorizer::install();     // trusts the test key, fakes the HTTP endpoints
$authorizer->grant($user);                   // a valid authorization in the session, for this user
$authorizer->setFlags(['check-in' => true]); // a known flag state, no authorization needed
```

* `grant(?Authenticatable $user = null, array $claims = [], ?Signer $signer = null): string` puts an assertion in the session. Override claims or the signer to build one that must be refused.
* `setFlags(array $flags): void`, `flags(): array`, `version(): int` and `writes(): int` describe what the fake authorizer did.
* `signer(): Signer` gives access to the test key pair for anything else that needs a signed token.

Call `install()` once the configuration of the application is in place: it adds the test public key to `keys` and fakes the `/v1/flags` endpoints of the configured `url`.

## Limits

* Whoever can modify the source of the application can remove any check. The purpose is that doing so needs a change to the source, which is visible and overwritten by the next deploy.
* Somebody who controls the machine can copy an open session while it lasts, up to one hour after the assertion was issued (one hour and fifteen minutes for Livewire requests served during the grace).
* With outbound traffic blocked, an installation keeps the manifest it has.
* The defaults of the flags are reachable by deleting the manifest.

## License

MIT. See `LICENSE.md`.
