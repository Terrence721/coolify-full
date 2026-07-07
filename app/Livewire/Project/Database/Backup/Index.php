<?php

declare(strict_types=1);

namespace App\Livewire\Project\Database\Backup;

use App\Support\DatabaseEngineRegistry;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public $database;

    public function mount()
    {
        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }
        $environment->load(['applications']);
        $database = $environment->databases()->where('uuid', request()->route('database_uuid'))->first();
        if (! $database) {
            return redirect()->route('dashboard');
        }
        // No backups
        if (! (DatabaseEngineRegistry::forInstance($database)?->supportsBackup ?? true)) {
            return redirect()->route('project.database.configuration', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'database_uuid' => $database->uuid,
            ]);
        }
        $this->database = $database;
    }

    public function render(): Factory|View
    {
        return view('livewire.project.database.backup.index');
    }
}
