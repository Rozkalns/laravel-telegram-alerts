<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use Livewire\Component;

final class ProbeComponent extends Component
{
    /** @var list<string> */
    public array $items = [];

    public function render(): string
    {
        return '<div>probe</div>';
    }
}
