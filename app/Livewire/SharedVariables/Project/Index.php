<?php

declare(strict_types=1);

namespace App\Livewire\SharedVariables\Project;

use App\Models\Project;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;

class Index extends Component
{
    public Collection $projects;

    public function mount()
    {
        $this->projects = Project::ownedByCurrentTeamCached();
    }

    public function render(): Factory|View
    {
        return view('livewire.shared-variables.project.index');
    }
}
