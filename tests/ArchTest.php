<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->each->not->toBeUsed();

arch('production code does not depend on the test helpers')
    ->expect('PlinCode\PlatformAuthorizer')
    ->not->toUse('PlinCode\PlatformAuthorizer\Testing')
    ->ignoring('PlinCode\PlatformAuthorizer\Testing');

arch('production code does not depend on Filament')
    ->expect('PlinCode\PlatformAuthorizer')
    ->not->toUse('Filament');
