<?php

namespace PlinCode\PlatformAuthorizer\Tests\Elsewhere;

use Livewire\Component;

/**
 * A component outside the protected namespace, standing in for a page of a
 * panel that anybody can use.
 */
class OtherPanel extends Component
{
    public function render(): string
    {
        return '<div>other</div>';
    }
}
