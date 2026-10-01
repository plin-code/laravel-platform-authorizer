# Feature flags

[Back to the README](../README.md)

The flags of an installation live in a manifest signed by the authorizer. The package stores it in the `feature_manifests` table and verifies it on every read, and exposes it to Laravel Pennant through its own driver.

## Register the Pennant store

Install Pennant if the application does not have it yet (`composer require laravel/pennant`, then publish its configuration). The driver replaces Pennant's own storage, so Pennant's `features` table is not used. Write the store name in the file, without reading it from the environment:

```php
// config/pennant.php
'default' => 'platform-authorizer',

'stores' => [
    'platform-authorizer' => ['driver' => 'platform-authorizer'],
],
```

The `feature_manifests` table comes from the migration of the package:

```bash
php artisan vendor:publish --tag=platform-authorizer-migrations
php artisan migrate
```

## Define flags

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

The defaults are reachable by whoever owns the database (deleting the manifest is enough), so a flag that unlocks something licensed should default to `false`. A flag that is neither in the manifest nor defined is `false`.

## Read flags

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

The manifest is read and verified once per request, however many flags are asked for. When there is no manifest, or it does not verify, or the table cannot be read, every flag takes its default and the log says why (see [Troubleshooting](troubleshooting.md)).

## Write flags

Writes go through the authorizer: the package sends the complete flag set with the assertion of the session as bearer, and stores the signed manifest that comes back. They only work in a request that holds an assertion, that is inside the protected area (see [Protecting routes](protecting-routes.md)).

```php
use Laravel\Pennant\Feature;

$flags = Feature::for(config('platform-authorizer.global_scope'));

$flags->activate('check-in');
$flags->deactivate('custom-fields');
$flags->forget('custom-fields'); // removes it from the manifest, back to its default
```

`Feature::activateForEveryone()`, `Feature::deactivateForEveryone()` and `Feature::purge()` work too. `Feature::purge()` without arguments removes every flag from the manifest, so all of them go back to their defaults. A write for any scope other than `global_scope` throws `UnsupportedScopeException`, so `Feature::activate('check-in')`, which uses the logged in user as scope, is refused.

A write that would not change the flag set is not sent to the authorizer.

A write can throw:

* `AuthorizationRejectedException` (403): no assertion in the session, or the authorizer refused it (identity revoked, installation deactivated).
* `AuthorizationExpiredException` (`expired_status`, 419 by default): the assertion has expired.
* `AuthorizerUnavailableException` (503): the authorizer is unreachable, too slow, rate limiting or answering something that cannot be trusted (including a manifest that does not verify or is older than the stored one).

All three are HTTP exceptions, so Laravel renders them as error pages. Their messages come from the package translations, see [Customisation](customisation.md).

## Keep flags in sync

The package schedules `platform-authorizer:sync-flags` every hour on its own (without overlapping runs). It downloads the latest manifest, so a flag changed on the authorizer reaches the installation without anyone opening the panel, and it refuses a version older than the stored one. All you need is the Laravel scheduler running, as usual:

```cron
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1
```

Run it by hand after the first deploy, or whenever you want the latest flags at once:

```bash
php artisan platform-authorizer:sync-flags
```

Until the authorizer has issued a first manifest for the installation, the command says so and the flags keep their defaults. The command exits with a failure when the configuration is invalid, the authorizer is not available, or the manifest it receives is not accepted.
