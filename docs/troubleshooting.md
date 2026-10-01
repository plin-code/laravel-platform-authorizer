# Troubleshooting

[Back to the README](../README.md)

The package logs short reason codes and setting names. It never logs a token, a nonce, an assertion, an email or a claim. Start with the [gotchas](configuration.md#requirements-and-gotchas): most refusals come from one of them.

## Log messages

| Log message | Context | Meaning |
| --- | --- | --- |
| `Platform authorization refused` | `reason` | The callback refused what came back from the authorizer. See the reasons below. |
| `Platform request refused` | `reason` | A request to the protected area was refused. See the reasons below. |
| `Platform authorizer configuration is invalid` | `setting` | A value in `config/platform-authorizer.php` is not usable. The setting is named, never its value. See [Configuration](configuration.md). |
| `Platform authorizer configuration is invalid, shipped defaults in use` | `setting` | The same, noticed while reading the flags: every flag takes its default. |
| `Feature manifest missing, shipped defaults in use` | `installation`, `reason: missing` | There is no manifest for this installation yet. Run `platform-authorizer:sync-flags`. If the authorizer has none either, the first flag write creates it. Logged at most once per request. |
| `Feature manifest refused, shipped defaults in use` | `installation`, `reason` | The stored manifest did not verify. The reason is one of the codes below (`malformed`, `unknown_kid`, `bad_signature`, `wrong_audience` or `wrong_product`). |
| `Feature manifest unreadable, shipped defaults in use` | `installation`, `reason: unreadable` | The `feature_manifests` table cannot be read. Run the migration. |
| `Platform authorizer unreachable` | `endpoint` | No answer within `timeout`. |
| `Platform authorizer unavailable` | `endpoint`, `status` | The authorizer answered 400, 429, a 5xx or a redirect. |
| `Platform authorizer answered unexpectedly` | `endpoint`, `status` | Any other answer that had no manifest in it. |
| `Platform authorizer refused a flag write` | `endpoint`, `status` | 401 or 403 on a flag write: the identity was revoked, the installation deactivated, or the assertion was not accepted. An expired assertion is not logged here, it throws `AuthorizationExpiredException`. |
| `Platform authorizer answered with a manifest that was not accepted` | `outcome` | A flag write returned a manifest that does not verify (`invalid`) or is older than the stored one (`older`). |
| `Platform authorizer manifest not accepted during synchronisation` | `outcome` | The same, during `sync-flags`. |

The `endpoint` is `flags.read` (the synchronisation) or `flags.write` (a flag write).

## Reasons

| Reason | Meaning and usual cause |
| --- | --- |
| `refused_by_authorizer` | The authorizer answered `access_denied`: the vendor user cancelled, is not authorised for this installation, was revoked, or the installation is deactivated. |
| `missing_assertion` | The callback was opened without an assertion. |
| `missing_nonce` | The session lost the nonce of the round trip: a session shorter than 15 minutes, a session cookie that was not sent back (`same_site` set to `strict`), or the callback opened a second time. |
| `missing` | A Livewire request without any assertion in the session. |
| `configuration` | The configuration is invalid. The assertion in the session is kept. |
| `malformed` | Not a well formed token, or a claim missing or of the wrong type. |
| `unknown_kid` | The token was signed with a key that is not in `keys`. During a [key rotation](key-rotation.md) it means the new key was activated before it was deployed here, or the old key was removed too early. Otherwise ask the vendor for the current public key. |
| `bad_signature` | The signature does not match: the token or the stored manifest was altered, or the public key under that key id is not the right one. |
| `wrong_issuer` | `url` is not exactly the issuer of the authorizer. |
| `wrong_audience` | The token was issued for another installation. Check `PLATFORM_INSTALLATION`. |
| `wrong_product` | The token was issued for another product. Check `product`. |
| `wrong_nonce` | The assertion was issued for another round trip, for instance an older callback link opened while a new round trip was in progress. |
| `email_mismatch` | The email in the assertion is not the email of the logged in user, or nobody is logged in. Give the vendor user a local account with the same email, or ask the vendor to fix the registered one. The assertion is forgotten. |
| `expired` | The assertion is more than one hour old. A navigation goes through the authorizer again, a Livewire request gets `expired_status` after the grace (see [Livewire](livewire.md)). |

The codes from `malformed` onwards are also carried by the `AssertionRejected` event.

## Events

`PlinCode\PlatformAuthorizer\Events\AssertionRejected` is dispatched whenever an assertion or a manifest is refused, so an application can listen to it, for instance to raise an alert on an unexpected key id. It carries:

* `subject`: `assertion` or `manifest` (the constants `AssertionRejected::ASSERTION` and `AssertionRejected::MANIFEST`);
* `reason`: `malformed`, `unknown_kid`, `bad_signature`, `wrong_issuer`, `wrong_audience`, `wrong_product`, `wrong_nonce`, `email_mismatch` or `expired`;
* `kid`: the key id the token declared when it is a short plain string, otherwise null.

It never carries a token, an email or a claim.

```php
use Illuminate\Support\Facades\Event;
use PlinCode\PlatformAuthorizer\Events\AssertionRejected;

Event::listen(function (AssertionRejected $event) {
    if ($event->reason === 'unknown_kid') {
        // alert the vendor
    }
});
```
