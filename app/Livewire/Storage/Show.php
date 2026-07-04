<?php

declare(strict_types=1);

namespace App\Livewire\Storage;

use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class Show extends Component
{
    use AuthorizesRequests;

    public $storage = null;

    public string $currentRoute = '';

    public int $backupCount = 0;

    public function mount()
    {
        $this->storage = S3Storage::ownedByCurrentTeam()->whereUuid(request()->storage_uuid)->first();
        if (! $this->storage) {
            abort(404);
        }
        $this->authorize('view', $this->storage);
        $this->currentRoute = request()->route()->getName();
        $this->backupCount = ScheduledDatabaseBackup::where('s3_storage_id', $this->storage->id)->count();
    }

    public function delete()
    {
        try {
            $this->authorize('delete', $this->storage);

            $this->storage->delete();

            return redirect()->route('storage.index');
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.storage.show');
    }
}
