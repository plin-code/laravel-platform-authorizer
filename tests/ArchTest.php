<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('production code does not read the environment')
    ->expect('env')
    ->not->toBeUsed();

arch('production code does not depend on the test helpers')
    ->expect('PlinCode\PlatformAuthorizer')
    ->not->toUse('PlinCode\PlatformAuthorizer\Testing')
    ->ignoring('PlinCode\PlatformAuthorizer\Testing');

arch('production code does not depend on Filament')
    ->expect('PlinCode\PlatformAuthorizer')
    ->not->toUse('Filament');

arch('production code does not use a JWT library')
    ->expect('PlinCode\PlatformAuthorizer')
    ->not->toUse(['Firebase\JWT', 'Lcobucci\JWT', 'Namshi\JOSE', 'Jose']);

arch('value objects are immutable')
    ->expect([
        'PlinCode\PlatformAuthorizer\Settings',
        'PlinCode\PlatformAuthorizer\Assertions\Assertion',
        'PlinCode\PlatformAuthorizer\Assertions\Verification',
        'PlinCode\PlatformAuthorizer\Manifests\Manifest',
        'PlinCode\PlatformAuthorizer\Jws\JwsResult',
        'PlinCode\PlatformAuthorizer\Events\AssertionRejected',
    ])
    ->toBeReadonly();
