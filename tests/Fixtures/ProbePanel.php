<?php

namespace PlinCode\PlatformAuthorizer\Tests\Fixtures;

use Livewire\Component;

/**
 * A component that stands in for a page of the protected panel.
 */
class ProbePanel extends Component
{
    public int $count = 0;

    public function increment(): void
    {
        $this->count++;
    }

    public function render(): string
    {
        return '<div>count: {{ $count }}</div>';
    }
}
