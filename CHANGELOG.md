# Changelog

All notable changes to `laravel-platform-authorizer` are documented here.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Unreleased

## 0.1.0 - 2026-10-01

First public release.

### Added

* Remote authorization of a vendor panel: a round trip through the vendor's authorizer ends with a signed assertion (Ed25519, one hour), verified on every request against installation, product and the logged in user.
* `RequirePlatformAuthorization` middleware and `PlatformAuthorization` facade, with a refusal page in English and Italian.
* Livewire support: 403 without authorization, a configurable grace period and `expired_status` (419 or 403) after expiry, protected component namespaces.
* Signed feature flags through a Pennant driver: reads verify the stored manifest, writes go through the authorizer, `platform-authorizer:sync-flags` runs hourly.
* Key rotation with several public keys in the configuration.
* `AssertionRejected` event and log lines with the specific refusal reason, never with tokens or emails.
* `FakeAuthorizer` for application tests.
