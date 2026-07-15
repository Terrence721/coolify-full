<?php

declare(strict_types=1);

namespace App\Livewire\Server;

use App\Models\PrivateKey;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
    }

    public function render(): Factory|View
    {
        return view('livewire.server.create');
    }
}
