# Customisation

[Back to the README](../README.md)

## The refusal page

When the authorizer does not let a user in, the package shows a page (status 403) that says the area could not be opened, with a link to `denied_url`. It is the same for every cause: a page that told the reasons apart would help someone probing the check. The reason is in the log, see [Troubleshooting](troubleshooting.md).

To change the page, publish the view:

```bash
php artisan vendor:publish --tag=platform-authorizer-views
```

and edit `resources/views/vendor/platform-authorizer/denied.blade.php`. The view receives `$link`, the validated `denied_url` (or `/` when the configuration is invalid).

## Texts and languages

The package ships English (`en`) and Italian (`it`). `APP_LOCALE` drives the language of the refusal page and of the exception messages, and other locales fall back to `APP_FALLBACK_LOCALE`.

To change the texts (the refusal page and the messages of the exceptions), publish the translations:

```bash
php artisan vendor:publish --tag=platform-authorizer-translations
```

and edit `lang/vendor/platform-authorizer/<locale>/messages.php`. Add a folder for another locale to support it. The keys live in the `platform-authorizer::messages` namespace:

| Key | Used for |
| --- | --- |
| `denied_title` | Title of the refusal page |
| `denied_body` | Text of the refusal page |
| `denied_link` | Label of the link to `denied_url` |
| `authorization_refused` | Message of `AuthorizationRejectedException` |
| `flags_need_authorization` | Message of `AuthorizationRejectedException` for a flag write without an assertion |
| `authorization_expired` | Message of `AuthorizationExpiredException` |
| `authorizer_unavailable` | Message of `AuthorizerUnavailableException` |

These messages reach the user on the error page or in the Livewire error modal (see [Livewire](livewire.md)).

## Publish tags

| Tag | What it publishes |
| --- | --- |
| `platform-authorizer-config` | `config/platform-authorizer.php`, see [Configuration](configuration.md) |
| `platform-authorizer-migrations` | The migration of the `feature_manifests` table |
| `platform-authorizer-views` | The refusal page |
| `platform-authorizer-translations` | The English and Italian texts |
