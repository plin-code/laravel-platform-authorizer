<?php

return [
    /*
    | Base URL of the vendor's authorizer. Set it in the committed
    | configuration of the application, never from the environment.
    */
    'url' => null,

    /*
    | Slug of the product this application belongs to.
    */
    'product' => null,

    /*
    | Slug of this installation, as registered on the authorizer. It is the
    | only value read from the environment.
    */
    'installation' => env('PLATFORM_INSTALLATION'),

    /*
    | Public keys the authorizer signs with: key id => base64 Ed25519 public
    | key. Keep more than one entry while a key is being rotated.
    */
    'keys' => [],

    /*
    | Seconds to wait for the authorizer before failing closed.
    */
    'timeout' => 3,

    /*
    | Seconds after the expiry of an authorization during which a Livewire
    | request already in flight is still served.
    */
    'livewire_grace_seconds' => 900,

    /*
    | Status answered to a request whose authorization has expired: 419 makes
    | Livewire offer to reload the page, which goes through the authorizer
    | again, and 403 makes it show an error modal instead. Only these two are
    | accepted.
    */
    'expired_status' => 419,

    /*
    | Livewire components whose class lives under one of these namespaces
    | refuse to hydrate without a valid authorization.
    */
    'protected_livewire_namespaces' => [],

    /*
    | Route names the middleware lets through, for instance a logout route.
    */
    'except_routes' => [],

    /*
    | Scope Pennant uses for flags that are global. Writes for any other
    | scope are refused.
    */
    'global_scope' => '__global__',

    /*
    | Authentication guard whose user is compared with the authorization.
    | Null means the default guard.
    */
    'guard' => null,

    /*
    | Where to go after a successful authorization when no intended URL is
    | stored, and where the refusal page sends the user.
    */
    'home' => '/',
    'denied_url' => '/',

    /*
    | Prefix and middleware of the routes that start and complete the
    | round trip through the authorizer.
    */
    'route_prefix' => 'platform-authorizer',
    'middleware' => ['web', 'auth'],
];
