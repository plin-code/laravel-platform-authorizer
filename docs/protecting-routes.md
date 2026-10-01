# Protecting routes

[Back to the README](../README.md)

## The middleware

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

* A navigation without an assertion, or with an expired one, is redirected to the authorizer and comes back where it was going (the URL is stored as the intended URL).
* A request whose assertion is present but not valid (bad signature, another installation, another product, another user) is refused with a 403 and the assertion is forgotten.
* A route listed in `except_routes` is let through, for instance the logout route of a refused user.
* An invalid configuration refuses the request with a 403 and logs the name of the setting. The assertion in the session is kept.
* A Livewire request is never redirected, see [Livewire](livewire.md).

The middleware is registered as Livewire persistent middleware by the package, so Livewire update requests of a protected page are checked again. Nothing needs to be added for that.

## The routes of the round trip

The package registers two routes under `route_prefix`, with the middleware listed in `middleware` (`web` and `auth` by default):

* `platform-authorizer.redirect` (`GET /platform-authorizer/redirect`) starts the round trip.
* `platform-authorizer.callback` (`GET /platform-authorizer/callback`) completes it. Its full URL is the one to register on the authorizer, see [Configuration](configuration.md#what-you-need-from-the-vendor).

After a successful round trip the browser goes to the intended URL, or to `home` when none is stored. A refusal shows a generic page with status 403, see [Customisation](customisation.md).

## Gates and other checks

To ask the same question elsewhere, use the facade:

```php
use PlinCode\PlatformAuthorizer\Facades\PlatformAuthorization;

PlatformAuthorization::isGranted(); // true when the session holds a valid, unexpired assertion for the logged in user
```

For instance, to open Horizon only to an authorized vendor user:

```php
use Illuminate\Support\Facades\Gate;
use PlinCode\PlatformAuthorizer\Facades\PlatformAuthorization;

Gate::define('viewHorizon', fn ($user = null): bool => $user !== null && PlatformAuthorization::isGranted());
```

A gate only answers yes or no: it does not start the round trip. The user has to open a route behind the middleware first to get an assertion.
