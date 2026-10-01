# How it works

[Back to the README](../README.md)

## The problem

You sell a Laravel application that each customer runs on their own server. You (the vendor) need an area inside it, for instance a panel that switches licensed features on and off, that the customer's staff cannot open even though they own the server, the database and the `.env` file.

## The solution

This package closes that area behind a round trip to an **authorizer**, a small service run by the software vendor (the Go service `plincode-authorizer`). A vendor user logs in on the authorizer, confirms which installation they are opening, and comes back with a signed **assertion**. The feature flags of the installation live in a **manifest** that the same authorizer signs.

The exchange is described step by step in [Protocol](protocol.md).

## Trust model

* The authorizer holds the private Ed25519 keys. The application only holds the matching public keys, committed in its configuration. **The application never holds a secret**, so there is nothing on the customer's server that can be used to forge an authorization.
* The assertion is kept in the session as it is and verified again on every request. A session row written by hand grants nothing.
* The manifest is stored in the database but verified on every read. A row edited by hand has no effect, and a flag can only be changed by asking the authorizer to sign a new manifest, which needs a valid assertion.
* Every failure closes the door: an unreachable authorizer, a bad signature, a wrong nonce or an identity that is not authorised all end in a generic refusal.
* Every value that decides who gets in is written in the committed configuration file, not read from `.env`. The only exception is the installation slug. See [Configuration](configuration.md).

## Limits

What it does not stop is someone who edits the source code of the application.

* Whoever can modify the source of the application can remove any check. The purpose is that doing so needs a change to the source, which is visible and overwritten by the next deploy.
* Somebody who controls the machine can copy an open session while it lasts, up to one hour after the assertion was issued (one hour and fifteen minutes for Livewire requests served during the grace, see [Livewire](livewire.md)).
* With outbound traffic blocked, an installation keeps the manifest it has.
* The defaults of the flags are reachable by deleting the manifest, so a flag that unlocks something licensed should default to `false`. See [Feature flags](feature-flags.md).
