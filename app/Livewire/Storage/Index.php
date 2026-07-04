<?php

declare(strict_types=1);

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Index extends Component
{
    public $s3;

    public function mount()
    {
        $this->s3 = S3Storage::ownedByCurrentTeam()->get();
    }

    public function render(): Factory|View
    {
        return view('livewire.storage.index');
    }
}
