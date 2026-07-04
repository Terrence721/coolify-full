<?php

declare(strict_types=1);

namespace App\Livewire;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class LayoutPopups extends Component
{
    public function getListeners()
    {
        $teamId = currentTeam()->id;

        return [
            "echo-private:team.{$teamId},TestEvent" => 'testEvent',
        ];
    }

    public function testEvent()
    {
        $this->dispatch('success', 'Realtime events configured!');
    }

    public function render(): Factory|View
    {
        return view('livewire.layout-popups');
    }
}
