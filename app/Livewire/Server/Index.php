<?php

declare(strict_types=1);

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class Index extends Component
{
    public ?Collection $servers = null;

    public function mount()
    {
        $this->servers = Server::ownedByCurrentTeamCached();
    }

    public function render(): Factory|View
    {
        return view('livewire.server.index');
    }
}
