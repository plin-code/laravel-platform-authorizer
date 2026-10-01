# Protocol

[Back to the README](../README.md)

This is what the package exchanges with the authorizer. Every call has the configured timeout, none is retried and none follows a redirect (a 3xx answer counts as an unavailable authorizer, so the assertion sent as bearer cannot travel to another host).

## The round trip

1. The middleware stores a random nonce (64 lowercase hex characters) in the session and redirects the browser to

   ```
   GET <url>/v1/authorize?product=<product>&installation=<installation>&nonce=<nonce>
   ```

2. The authorizer checks the installation and the product, has the vendor user log in (with GitHub) and shows a confirmation page where they type the name of the installation.
3. The authorizer redirects the browser to the registered callback URL with one of

   ```
   GET /platform-authorizer/callback?assertion=<jws>
   GET /platform-authorizer/callback?error=access_denied
   ```

   `access_denied` covers a cancelled confirmation, an identity that is not authorised or revoked, a deactivated installation and any failure on the authorizer.
4. The callback takes the nonce out of the session (it serves one attempt, whatever happens), verifies the assertion against it and against the logged in user, keeps it in the session and redirects to the intended URL or to `home`.

On every later request the assertion in the session is verified again (signature, issuer, installation, product, email and expiry), without the nonce. See [Protecting routes](protecting-routes.md).

## Flags

```
POST <url>/v1/flags
Authorization: Bearer <assertion>
Content-Type: application/json

{"installation": "<installation>", "flags": {"check-in": true, "custom-fields": false}}
```

The flag set is always the complete set, as a JSON object (an empty set is sent as `{}`). The authorizer accepts the bearer until `exp` plus 30 seconds, and only while the installation is active and the identity is not revoked. It accepts up to 200 flags, with names of up to 100 characters. Answers:

| Status | Body | What the package does |
| --- | --- | --- |
| 200 | `{"manifest": "<jws>"}` | verifies and stores the manifest |
| 401 | `{"error": "expired"}` | throws `AuthorizationExpiredException` |
| 401 | `{"error": "invalid"}` | throws `AuthorizationRejectedException` |
| 403 | `{"error": "forbidden"}` | throws `AuthorizationRejectedException` |
| 400, 429, 5xx, 3xx, no answer | | throws `AuthorizerUnavailableException` |
| any other answer without a manifest | | throws `AuthorizerUnavailableException` |

```
GET <url>/v1/flags/<installation>
```

Answers 200 with `{"manifest": "<jws>"}`, or 404 with `{"error": "not_found"}` when no manifest has been issued yet. It needs no credentials: a manifest is not a secret, and it is only accepted when its signature verifies. This is the call made by `platform-authorizer:sync-flags`, see [Feature flags](feature-flags.md).

## Token format

Assertions and manifests are compact JWS tokens: `base64url(header).base64url(payload).base64url(signature)`, without padding. The header is `{"alg":"EdDSA","kid":"<key id>"}`, and `EdDSA` (Ed25519) is the only algorithm accepted. The signature covers the exact bytes `<header>.<payload>` as received. The `kid` selects the public key in `keys` (see [Key rotation](key-rotation.md)). The header and the payload must be JSON objects.

Claims of an **assertion**, all required with exactly these types (strings must not be empty):

| Claim | Type | Meaning |
| --- | --- | --- |
| `iss` | string | the authorizer, must equal `url` |
| `aud` | string | the installation slug |
| `prd` | string | the product slug |
| `sub` | string | the vendor identity, `github:<numeric id>` |
| `email` | string | the email of the vendor user, compared with the local user ignoring case |
| `nonce` | string | the nonce of the round trip |
| `iat` | integer | issued at, Unix seconds |
| `exp` | integer | expiry, one hour after `iat` |
| `jti` | string | unique id, also written in the authorizer's audit log |

The package tolerates 30 seconds of clock skew on `exp`. The checks run in a fixed order and the expiry comes last, so an assertion is only reported as expired when everything else about it is right.

Claims of a **manifest**, all required:

| Claim | Type | Meaning |
| --- | --- | --- |
| `aud` | string | the installation slug |
| `prd` | string | the product slug |
| `ver` | integer, at least 1 | version, grows with every write. A manifest with a lower version than the stored one is refused, the same version is accepted |
| `iat` | integer | issued at, Unix seconds |
| `flags` | object | flag name to boolean |
