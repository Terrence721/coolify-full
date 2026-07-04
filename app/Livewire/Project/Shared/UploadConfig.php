<?php

declare(strict_types=1);

namespace App\Livewire\Project\Shared;

use App\Models\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class UploadConfig extends Component
{
    public $config;

    public $applicationId;

    public function mount()
    {
        if (isDev()) {
            $this->config = '{
    "build_pack": "nixpacks",
    "base_directory": "/nodejs",
    "publish_directory": "/",
    "ports_exposes": "3000",
    "settings": {
        "is_static": false
    }
}';
        }
    }

    public function uploadConfig()
    {
        try {
            $application = Application::findOrFail($this->applicationId);
            $application->setConfig($this->config);
            $this->dispatch('success', 'Application settings updated');
        } catch (\Exception $e) {
            $this->dispatch('error', $e->getMessage());

            return;
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.project.shared.upload-config');
    }
}
