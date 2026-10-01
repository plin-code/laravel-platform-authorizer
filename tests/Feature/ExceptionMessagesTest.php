<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\Route;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationExpiredException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizationRejectedException;
use PlinCode\PlatformAuthorizer\Exceptions\AuthorizerUnavailableException;
use PlinCode\PlatformAuthorizer\Manifests\FlagWriter;
use PlinCode\PlatformAuthorizer\Testing\Signer;

it('builds the messages users see from the translations', function (string $locale, array $expected) {
    app()->setLocale($locale);

    expect((new AuthorizationRejectedException)->getMessage())->toBe($expected[0])
        ->and(AuthorizationRejectedException::forFlagWrite()->getMessage())->toBe($expected[1])
        ->and((new AuthorizationExpiredException)->getMessage())->toBe($expected[2])
        ->and((new AuthorizerUnavailableException)->getMessage())->toBe($expected[3]);
})->with([
    'english' => ['en', [
        'The platform authorization was refused.',
        'Changing feature flags needs a platform authorization.',
        'The platform authorization has expired.',
        'The platform authorizer is not available.',
    ]],
    'italian' => ['it', [
        "L'autorizzazione della piattaforma è stata rifiutata.",
        "Per modificare i flag delle funzionalità serve l'autorizzazione della piattaforma.",
        "L'autorizzazione della piattaforma è scaduta.",
        'Il servizio di autorizzazione della piattaforma non è disponibile.',
    ]],
    'a locale the package does not ship falls back to english' => ['de', [
        'The platform authorization was refused.',
        'Changing feature flags needs a platform authorization.',
        'The platform authorization has expired.',
        'The platform authorizer is not available.',
    ]],
]);

it('keeps a message given explicitly', function () {
    expect((new AuthorizationRejectedException('custom'))->getMessage())->toBe('custom')
        ->and((new AuthorizerUnavailableException('custom'))->getMessage())->toBe('custom');
});

it('shows the refusal of a flag write in the language of the application', function () {
    config()->set('platform-authorizer', configFor(new Signer));
    config()->set('app.locale', 'it');
    Route::middleware('web')->get('/toggle', function () {
        app(FlagWriter::class)->write(['check-in' => true]);
    });

    $this->actingAs(new GenericUser(['id' => 1, 'email' => 'vendor@example.test']))
        ->get('/toggle')
        ->assertForbidden()
        ->assertSee('Per modificare i flag delle funzionalità serve l&#039;autorizzazione della piattaforma.', false)
        ->assertDontSee('Changing feature flags');
});
