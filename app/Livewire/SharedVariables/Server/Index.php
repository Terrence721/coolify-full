<?php

declare(strict_types=1);

namespace App\Livewire\SharedVariables\Server;

use App\Models\Server;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $servers;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeamCached();
    }

    public function render(): Factory|View
    {
        return view('livewire.shared-variables.server.index');
    }
}
