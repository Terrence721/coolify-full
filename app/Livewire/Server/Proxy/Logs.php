<?php

declare(strict_types=1);

namespace App\Livewire\Server\Proxy;

use App\Models\Server;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Logs extends Component
{
    public ?Server $server = null;

    public $parameters = [];

    public function mount()
    {
        $this->parameters = get_route_parameters();
        try {
            $this->server = Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->first();
            if (is_null($this->server)) {
                return redirect()->route('server.index');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.server.proxy.logs');
    }
}
