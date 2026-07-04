<?php

declare(strict_types=1);

namespace App\Livewire\Server;

use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Models\Team;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Create extends Component
{
    public $private_keys = [];

    public bool $limit_reached = false;

    public bool $has_hetzner_tokens = false;

    public function mount()
    {
        $this->private_keys = PrivateKey::ownedByCurrentTeamCached();
        if (! isCloud()) {
            $this->limit_reached = false;

            return;
        }
        $this->limit_reached = Team::serverLimitReached();

        // Check if user has Hetzner tokens
        $this->has_hetzner_tokens = CloudProviderToken::ownedByCurrentTeam()
            ->where('provider', 'hetzner')
            ->exists();
    }

    public function render(): Factory|View
    {
        return view('livewire.server.create');
    }
}
