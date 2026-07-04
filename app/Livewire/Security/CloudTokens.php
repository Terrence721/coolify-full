<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class CloudTokens extends Component
{
    public function render(): Factory|View
    {
        return view('livewire.security.cloud-tokens');
    }
}
