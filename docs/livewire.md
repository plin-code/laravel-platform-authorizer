# Livewire

[Back to the README](../README.md)

## How Livewire requests are answered

A Livewire request cannot follow a redirect to another domain, so the [middleware](protecting-routes.md) answers it differently:

* no assertion, or an assertion that is not valid: 403;
* an expired assertion: served for `livewire_grace_seconds` after the expiry, then the status of `expired_status`, 419 by default.

The two values of `expired_status` differ in what Livewire does with them. A 419 makes Livewire ask the user with its native confirm dialog (a notice that the page has expired) and reload the page only if the user accepts. The dialog appears once per page load, and the reload goes through the authorizer again. A 403 makes Livewire show its error modal with the error page inside, and the only way out is to close it and reload the page by hand. Keep 419 unless the modal is what you want. Any other value is refused when the configuration is validated.

The grace applies only to the real Livewire update endpoint (a POST to Livewire's update URI). A plain request that carries the `X-Livewire` header gets none.

## Protected components

Livewire replays the middleware of the page a component was rendered on, and the page is named by the component snapshot. The snapshot is signed with the application key, which the owner of the server holds, so the page it names can be forged. Set `protected_livewire_namespaces` to the namespaces of the components of the protected area and they also check the authorization when they hydrate, whatever the snapshot says.

```php
// config/platform-authorizer.php
'protected_livewire_namespaces' => [
    'App\\Livewire\\Platform',
],
```

A component is protected when its class name starts with one of the namespaces followed by a backslash. A protected component answers like a Livewire request to the middleware: a 403, or `expired_status` once the grace is over.

## A minimal panel

```php
<?php

namespace App\Livewire\Platform;

use Laravel\Pennant\Feature;
use Livewire\Component;

class PlatformPanel extends Component
{
    public function toggle(string $flag): void
    {
        $flags = Feature::for(config('platform-authorizer.global_scope'));

        $flags->active($flag) ? $flags->deactivate($flag) : $flags->activate($flag);
    }

    public function render()
    {
        $flags = Feature::for(config('platform-authorizer.global_scope'));

        return view('livewire.platform.panel', [
            'checkIn' => $flags->active('check-in'),
            'customFields' => $flags->active('custom-fields'),
        ]);
    }
}
```

```blade
{{-- resources/views/livewire/platform/panel.blade.php --}}
<div>
    <label>
        <input type="checkbox" wire:click="toggle('check-in')" @checked($checkIn)>
        Check-in
    </label>
    <label>
        <input type="checkbox" wire:click="toggle('custom-fields')" @checked($customFields)>
        Custom fields
    </label>
</div>
```

The component lives under `App\Livewire\Platform`, which is listed in `protected_livewire_namespaces`, and its route is behind the middleware (see [Protecting routes](protecting-routes.md)). The flags it toggles are described in [Feature flags](feature-flags.md).
