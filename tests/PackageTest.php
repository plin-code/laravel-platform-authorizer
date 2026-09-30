<?php

use PlinCode\PlatformAuthorizer\PlatformAuthorizerServiceProvider;

it('registers the service provider', function () {
    expect(app()->getProvider(PlatformAuthorizerServiceProvider::class))->not->toBeNull();
});
