<?php

declare(strict_types=1);

namespace App\Livewire\Profile;

use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Appearance extends Component
{
    public function render(): Factory|View
    {
        return view('livewire.profile.appearance');
    }
}
