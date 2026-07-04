<?php

declare(strict_types=1);

namespace App\Livewire\Subscription;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public function mount()
    {
        if (! isCloud()) {
            return redirect()->route('dashboard');
        }
        if (auth()->user()?->isMember()) {
            return redirect()->route('dashboard');
        }
        if (! data_get(currentTeam(), 'subscription')) {
            return redirect()->route('subscription.index');
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.subscription.show');
    }
}
