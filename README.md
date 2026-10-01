<p align="center">
  <img src="https://raw.githubusercontent.com/plin-code/laravel-platform-authorizer/main/art/banner.png" alt="Laravel Platform Authorizer">
</p>

# Laravel Platform Authorizer

<p align="center">
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/v/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/php-v/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://badge.laravel.cloud/badge/plin-code/laravel-platform-authorizer?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/plin-code/laravel-platform-authorizer/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/plin-code/laravel-platform-authorizer/run-tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/plin-code/laravel-platform-authorizer"><img src="https://img.shields.io/packagist/dt/plin-code/laravel-platform-authorizer.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Remote authorization for the vendor panel of a self-hosted Laravel application, and feature flags that only the vendor can change.

You sell a Laravel application that each customer runs on their own server, and you need an area inside it (for instance a panel that switches licensed features on and off) that the customer's staff cannot open, even though they own the server, the database and the `.env` file. This package closes that area behind a round trip to an **authorizer** run by the vendor, and keeps the feature flags in a **manifest** signed by the same authorizer.

* The application only holds Ed25519 public keys. **It never holds a secret**, so nothing on the customer's server can forge an authorization.
* The signed assertion in the session and the signed manifest in the database are verified again on every request. A row edited by hand grants nothing.
* Every failure closes the door with a generic refusal.

It does not stop someone who edits the source code. See [How it works](docs/how-it-works.md) for the trust model and its limits.

## Requirements

* PHP 8.3 or later with `ext-sodium`
* Laravel 12 or 13
* `laravel/pennant` for the flag driver (optional)
* `livewire/livewire` for the component checks (optional)

## Installation

```bash
composer require plin-code/laravel-platform-authorizer
php artisan vendor:publish --tag=platform-authorizer-config
php artisan vendor:publish --tag=platform-authorizer-migrations
php artisan migrate
```

The migration creates the `feature_manifests` table, which keeps the signed manifest of the installation.

## Quick Start

### 1. Configure

Ask the vendor for the authorizer URL, the product slug, the installation slug and the public keys. Give the vendor the callback URL of the installation (`https://<your app>/platform-authorizer/callback`) and the email of every local user who will open the panel: the assertion is only accepted for a logged in user with the same email.

Write the values in `config/platform-authorizer.php`, which belongs in version control. Only the installation slug comes from `.env`:

```php
'url' => 'https://authorizer.example.com',
'product' => 'acme-crm',
'installation' => env('PLATFORM_INSTALLATION'),
'keys' => [
    'acme-2026-1' => 'G1yHmSCN25mB4Cp3WRutDKtJLT5ZXQdTGOAPmLeAA28=',
],
'protected_livewire_namespaces' => [
    'App\\Livewire\\Platform',
],
```

```dotenv
PLATFORM_INSTALLATION=acme-crm-rossi
```

Keep `SESSION_LIFETIME` at 15 minutes or more and the session cookie `same_site` at `lax`. See [Configuration](docs/configuration.md) for every setting and the other gotchas.

### 2. Protect routes

```php
use App\Livewire\Platform\PlatformPanel;
use PlinCode\PlatformAuthorizer\Http\Middleware\RequirePlatformAuthorization;

Route::middleware(['auth', RequirePlatformAuthorization::class])
    ->prefix('platform')
    ->group(function () {
        Route::get('/', PlatformPanel::class)->name('platform');
    });
```

Put `auth` before the middleware: the assertion is bound to the email of the logged in user. A navigation without a valid assertion goes through the authorizer and comes back where it was going.

### 3. Read and write flags

Register the store in `config/pennant.php`:

```php
'default' => 'platform-authorizer',

'stores' => [
    'platform-authorizer' => ['driver' => 'platform-authorizer'],
],
```

Define each flag with its default, then read and write it as usual. Flags are global, and writes go through the authorizer, so they only work inside the protected area:

```php
use Laravel\Pennant\Feature;

Feature::define('check-in', fn () => false); // in a service provider, false unless the manifest says otherwise

Feature::active('check-in');

Feature::for(config('platform-authorizer.global_scope'))->activate('check-in');
```

The package synchronises the flags every hour through the Laravel scheduler. Run `php artisan platform-authorizer:sync-flags` once after the first deploy.

## Documentation

* [How it works](docs/how-it-works.md): the problem, the trust model and the limits.
* [Configuration](docs/configuration.md): what to exchange with the vendor, every setting with its default and validation, and the gotchas.
* [Protecting routes](docs/protecting-routes.md): the middleware, the round trip routes, gates (with a Horizon example).
* [Livewire](docs/livewire.md): expired status and grace, protected components, a minimal panel.
* [Feature flags](docs/feature-flags.md): the Pennant store, defining, reading and writing flags, exceptions, synchronisation.
* [Key rotation](docs/key-rotation.md): replacing the signing key without downtime.
* [Customisation](docs/customisation.md): the refusal page, translations and locales, publish tags.
* [Protocol](docs/protocol.md): the endpoints, the token format and the claims.
* [Troubleshooting](docs/troubleshooting.md): log messages, refusal reasons and the `AssertionRejected` event.
* [Testing your application](docs/testing.md): the `FakeAuthorizer` helper.

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
