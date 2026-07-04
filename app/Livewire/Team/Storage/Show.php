<?php

declare(strict_types=1);

namespace App\Livewire\Team\Storage;

use App\Models\S3Storage;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class Show extends Component
{
    public $storage = null;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.storage.show');
    }
}
