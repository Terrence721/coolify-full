<?php

declare(strict_types=1);

namespace App\Livewire\Server;

use App\Models\Server;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Swarm extends Component
{
    use AuthorizesRequests;

    public Server $server;

    public array $parameters = [];

    public bool $isSwarmManager;

    public bool $isSwarmWorker;

    public function mount(string $server_uuid)
    {
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
            $this->parameters = get_route_parameters();
            $this->syncData();
        } catch (\Throwable) {
            return redirect()->route('server.index');
        }
    }

    public function syncData(bool $toModel = false)
    {
        $settings = $this->server->settings()->first();
        if (! $settings) {
            return;
        }

        if ($toModel) {
            $this->authorize('update', $this->server);
            $settings->forceFill([
                'is_swarm_manager' => $this->isSwarmManager,
                'is_swarm_worker' => $this->isSwarmWorker,
            ]);
            $settings->save();
        } else {
            $this->isSwarmManager = (bool) data_get($settings, 'is_swarm_manager', false);
            $this->isSwarmWorker = (bool) data_get($settings, 'is_swarm_worker', false);
        }
    }

    public function instantSave()
    {
        try {
            $this->syncData(true);
            $this->dispatch('success', 'Swarm settings updated.');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.server.swarm');
    }
}
