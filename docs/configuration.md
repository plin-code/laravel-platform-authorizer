# Configuration

[Back to the README](../README.md)

## What you need from the vendor

Before installing, ask the vendor (whoever runs the authorizer) for:

* **The authorizer URL**, for instance `https://authorizer.example.com`. It is both the address the package calls and the issuer written in every assertion.
* **The product slug**, for instance `acme-crm`.
* **The installation slug** of this deployment, for instance `acme-crm-rossi`. Each customer server has its own.
* **The public key or keys**: for each one a key id and a base64 encoded Ed25519 public key, for instance `acme-2026-1` and `G1yHmSCN25mB4Cp3WRutDKtJLT5ZXQdTGOAPmLeAA28=`.

The vendor needs from you:

* **The callback URL** of the installation, to register it on the authorizer. It is `https://<your app>/<route_prefix>/callback`, that is `https://<your app>/platform-authorizer/callback` with the default `route_prefix`. The authorizer only ever sends the browser back to this registered URL.
* **The email of every local user who will open the panel.** Each vendor user is registered on the authorizer with an email, and the assertion carries it. The package lets the request through only when that email matches the email of the user logged in to the application (the comparison ignores case). A vendor user therefore needs a local account with the same email. The local email is read with `getEmailForPasswordReset()` when the user model implements `CanResetPassword` (Laravel's default `User` does), otherwise from its `email` attribute.

## The configuration file

Publish it with:

```bash
php artisan vendor:publish --tag=platform-authorizer-config
```

`config/platform-authorizer.php` belongs in version control. Every value except the installation slug is written in the file and not read from the environment: a value that decides who gets in must not be one line of `.env` away from being changed.

A complete example:

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

## Settings

The first four come from the vendor. The published file ships `url` and `product` as `null` and `keys` empty, so the package refuses every request until they are filled in.

| Key | Default | Meaning |
| --- | --- | --- |
| `url` | `null` | Base URL of the authorizer. Must be an absolute https URL without credentials, query string or fragment (http is accepted towards `localhost`, `127.0.0.1` and `[::1]` only). A trailing slash is removed. It is also the expected issuer of every assertion, see the gotchas below. |
| `product` | `null` | Slug of the product. |
| `installation` | `env('PLATFORM_INSTALLATION')` | Slug of this installation. |
| `keys` | `[]` | Public keys of the authorizer, `key id => base64 Ed25519 public key`. At least one is required, and more than one entry is allowed, see [Key rotation](key-rotation.md). |
| `timeout` | `3` | Seconds to wait for the authorizer (connection and answer), an integer between 1 and 10. Nothing is retried. |
| `livewire_grace_seconds` | `900` | How long after its expiry an assertion still serves Livewire requests already in flight, an integer between 0 and 3600. See [Livewire](livewire.md). |
| `expired_status` | `419` | Status answered to a request whose authorization has expired. Only the integers 403 and 419 are accepted, see [Livewire](livewire.md). |
| `protected_livewire_namespaces` | `[]` | Livewire components under these namespaces refuse to hydrate without a valid authorization. |
| `except_routes` | `[]` | Route names the middleware lets through, for instance a logout route. Patterns accepted by `Request::routeIs()` work. |
| `global_scope` | `'__global__'` | The Pennant scope of the flags. Writes for any other scope are refused. |
| `guard` | `null` | Guard whose user is compared with the assertion, the default guard when null. |
| `home` | `'/'` | Where to go after a successful authorization when no intended URL is stored. |
| `denied_url` | `'/'` | Target of the link on the refusal page. |
| `route_prefix`, `middleware` | `'platform-authorizer'`, `['web', 'auth']` | Prefix and middleware of the two routes of the round trip. |

Slugs (`product` and `installation`) are made of letters, digits, dots, underscores and hyphens, start with a letter or a digit, and are at most 100 characters long. `home` and `denied_url` must be a path that starts with a single slash, or an absolute http(s) URL. Lists must be lists of non-empty strings.

Unknown keys are ignored, so an application can keep its own settings in the same file. The configuration is validated when it is first used, not at boot, so artisan commands keep working on an installation that is not configured yet. An invalid value fails closed: requests to the protected area are refused, the flags fall back to their defaults, and the log says `Platform authorizer configuration is invalid` with the name of the setting, never its value. See [Troubleshooting](troubleshooting.md).

## Requirements and gotchas

* **`SESSION_LIFETIME` must be at least 15 minutes.** The nonce of the round trip lives in the session. On the authorizer a login can take up to 10 minutes and the confirmation page lasts 5 more, so a shorter session can lose the nonce before the browser comes back (`missing_nonce`). Laravel's default of 120 minutes is fine.
* **Keep the session cookie `same_site` at `lax`** (Laravel's default). The browser comes back from the authorizer through a redirect started on another site, and a `strict` cookie is not sent with it.
* **`APP_LOCALE` drives the language** of the refusal page and of the exception messages. The package ships English and Italian. Other locales fall back to `APP_FALLBACK_LOCALE`. See [Customisation](customisation.md).
* **`url` must use https.** Plain http is accepted only towards `localhost`, `127.0.0.1` and `[::1]`, for local testing.
* **`url` must be exactly the issuer of the authorizer** (its `AUTHORIZER_ISSUER`). The package removes a trailing slash from `url` and compares the `iss` claim with the result, so a different scheme, host, port or path, or an issuer that ends with a slash, refuses every assertion with `wrong_issuer`.
* **Each vendor user needs a local account with the same email** as on the authorizer, otherwise `email_mismatch`.
* **The callback URL registered on the authorizer must match `route_prefix`.** Changing the prefix means asking the vendor to register the new URL.
