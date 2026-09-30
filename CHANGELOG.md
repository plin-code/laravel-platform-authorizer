# Changelog

All notable changes to `laravel-platform-authorizer` are documented here.

## Unreleased

* First version: assertion verification, protected routes and Livewire components, signed feature flags through Pennant, synchronisation command and testing helpers.
* The authorizer client never follows a redirect. A 3xx answer is treated as an unavailable authorizer, so the assertion sent as bearer cannot travel to another host.
* A manifest must carry its flags as a JSON object. An empty JSON list is no longer taken for an empty flag set, and flag names made of digits are accepted and survive a flag write.
