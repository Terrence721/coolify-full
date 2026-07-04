<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Dashboard extends Component
{
    public Collection $projects;

    public Collection $servers;

    public Collection $privateKeys;

    public function mount()
    {
        $this->privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $this->servers = Server::ownedByCurrentTeamCached();
        $this->projects = Project::ownedByCurrentTeam()->with('environments')->get();
    }

    public function render(): Factory|View
    {
        return view('livewire.dashboard');
    }
}
