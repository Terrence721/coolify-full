<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Jobs\PullChangelog;
use App\Services\ChangelogService;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class SettingsDropdown extends Component
{
    public $showWhatsNewModal = false;

    public string $trigger = 'preferences';

    public function getUnreadCountProperty()
    {
        return Auth::user()->getUnreadChangelogCount();
    }

    public function getEntriesProperty(): Collection
    {
        $user = Auth::user();

        return app(ChangelogService::class)->getEntriesForUser($user);
    }

    public function getCurrentVersionProperty()
    {
        return 'v'.config('constants.coolify.version');
    }

    public function openWhatsNewModal()
    {
        $this->showWhatsNewModal = true;
    }

    public function closeWhatsNewModal()
    {
        $this->showWhatsNewModal = false;
    }

    public function markAsRead($identifier)
    {
        app(ChangelogService::class)->markAsReadForUser($identifier, Auth::user());
    }

    public function markAllAsRead()
    {
        app(ChangelogService::class)->markAllAsReadForUser(Auth::user());
    }

    public function manualFetchChangelog()
    {
        if (! isDev()) {
            return;
        }

        try {
            PullChangelog::dispatch();
            $this->dispatch('success', 'Changelog fetch initiated! Check back in a few moments.');
        } catch (\Throwable $e) {
            $this->dispatch('error', 'Failed to fetch changelog: '.$e->getMessage());
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.settings-dropdown', [
            'entries' => $this->getEntriesProperty(),
            'unreadCount' => $this->getUnreadCountProperty(),
            'currentVersion' => $this->getCurrentVersionProperty(),
        ]);
    }
}
