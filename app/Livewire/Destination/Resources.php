<?php

declare(strict_types=1);

namespace App\Livewire\Destination;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\Application;
use App\Models\BaseModel;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

class Resources extends Component
{
    #[Locked]
    public $destination;

    public array $resources = [];

    public function mount(string $destination_uuid)
    {
        try {
            $destination = find_destination_for_current_team($destination_uuid);
            if (! $destination) {
                return redirect()->route('destination.index');
            }
            if (! $destination instanceof StandaloneDocker) {
                return redirect()->route('destination.show', ['destination_uuid' => $destination->uuid]);
            }

            $this->destination = $destination;
            $this->loadResources();
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * Load applications, services, and database resources deployed to the standalone Docker destination.
     *
     * @return void Populates the resources property for display.
     */
    public function loadResources(): void
    {
        $groups = [
            $this->destination->applications,
            $this->destination->services,
        ];
        foreach (DatabaseEngineRegistry::relationNames() as $relationName) {
            $groups[] = $this->destination->{$relationName};
        }

        $this->resources = $this->collectResources($groups);
    }

    /**
     * @param  array<int, iterable<Application|Service|StandaloneDatabaseInstance>>  $groups
     * @return array<int, array{uuid:string,type:string,name:string,project:string|null,environment:string|null,url:string|null,search:string}>
     */
    protected function collectResources(array $groups): array
    {
        $rows = [];
        foreach ($groups as $group) {
            foreach ($group as $resource) {
                $rows[] = $this->resourceRow($resource);
            }
        }

        return $rows;
    }

    /**
     * @param  Application|Service|StandaloneDatabaseInstance  $resource
     * @return array{uuid:string,type:string,name:string,project:string|null,environment:string|null,url:string|null,search:string}
     */
    protected function resourceRow(BaseModel $resource): array
    {
        $type = match (true) {
            $resource instanceof Application => 'application',
            $resource instanceof Service => 'service',
            default => 'database',
        };
        $environment = $resource->environment;
        $project = $environment?->project;
        $routeName = "project.{$type}.configuration";
        $url = ($project && $environment)
            ? route($routeName, [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                "{$type}_uuid" => $resource->uuid,
            ])
            : null;

        return [
            'uuid' => $resource->uuid,
            'type' => $type,
            'name' => $resource->name,
            'project' => $project?->name,
            'environment' => $environment?->name,
            'url' => $url,
            'search' => strtolower(implode(' ', array_filter([
                $type,
                $resource->name,
                $project?->name,
                $environment?->name,
            ]))),
        ];
    }

    public function render(): View
    {
        return view('livewire.destination.resources');
    }
}
