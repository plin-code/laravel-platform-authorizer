# Changelog

All notable changes to `laravel-platform-authorizer` are documented here.

## Unreleased

* First version: assertion verification, protected routes and Livewire components, signed feature flags through Pennant, synchronisation command and testing helpers.
* The authorizer client never follows a redirect. A 3xx answer is treated as an unavailable authorizer, so the assertion sent as bearer cannot travel to another host.
* A manifest must carry its flags as a JSON object. An empty JSON list is no longer taken for an empty flag set, and flag names made of digits are accepted and survive a flag write.
* New setting `expired_status` (403 or 419, 419 by default) for the status answered to an expired authorization, by the middleware, the Livewire component check and a flag write alike.
* The messages of `AuthorizationRejectedException`, `AuthorizationExpiredException` and `AuthorizerUnavailableException`, which users see on the error page or in the Livewire modal, come from the translations (`platform-authorizer::messages`), in English and Italian. `AuthorizationRejectedException::forFlagWrite()` builds the refusal of a flag write without an authorization.
* A missing manifest is logged as a warning (`Feature manifest missing, shipped defaults in use`, reason `missing`), at most once per request, like a refused one.
* A refused manifest is logged with the specific reason (`unknown_kid`, `bad_signature`, `malformed`, `wrong_audience`, `wrong_product`) instead of `invalid`. `ManifestVerifier::check()` returns it along with the verdict.
* Removed `AuthorizerClient::reportTamper()` and `FakeAuthorizer::events()`: the authorizer has no events endpoint. The `AssertionRejected` event stays.
* The README is rewritten for a first time reader: what the vendor provides, a step by step setup, the protocol and a troubleshooting guide.
