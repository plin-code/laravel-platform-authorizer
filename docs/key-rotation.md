# Key rotation

[Back to the README](../README.md)

The `keys` setting (see [Configuration](configuration.md)) accepts several public keys, so a new key can be distributed before the authorizer starts signing with it. The `kid` in the header of every token selects the key that verifies it.

1. The vendor generates the new key on the authorizer. It does not sign anything yet.
2. Add the new public key to `keys`, next to the old one, and deploy every installation.
3. The vendor activates the new key. From now on assertions and manifests are signed with it, and the old key only verifies.
4. Keep the old public key until every installation holds a manifest signed with the new key (a flag write does it, and so does the hourly synchronisation once the vendor has re-signed the manifest on the authorizer) and the assertions signed with the old key have expired, which takes one hour.
5. Remove the old public key and deploy.

```php
// config/platform-authorizer.php, during steps 2 to 4
'keys' => [
    'acme-2026-1' => '<old base64 public key>',
    'acme-2026-2' => '<new base64 public key>',
],
```

A manifest already stored stays signed by the key that issued it. Removing the old key too early makes the flags fall back to their defaults until the next synchronisation, and the log says `unknown_kid`. Activating the new key before it is deployed has the same effect on assertions: every round trip ends with `unknown_kid`. See [Troubleshooting](troubleshooting.md).

Listening to the `AssertionRejected` event is a way to notice an unexpected key id early, see [Troubleshooting](troubleshooting.md#events).
