<?php

declare(strict_types=1);

namespace App\Livewire\Project\Shared;

use App\Contracts\StandaloneDatabaseInstance;
use App\Models\Application;
use App\Models\Service;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

class ConfigurationChecker extends Component
{
    public bool $isConfigurationChanged = false;

    public array $configurationDiff = [];

    public Application|Service|(Model&StandaloneDatabaseInstance) $resource;

    public function getListeners(): array
    {
        $teamId = currentTeam()->id;

        return [
            "echo-private:team.{$teamId},ApplicationConfigurationChanged" => 'configurationChanged',
            'configurationChanged' => 'configurationChanged',
        ];
    }

    public function mount(): void
    {
        $this->configurationChanged();
    }

    public function render(): View
    {
        return view('livewire.project.shared.configuration-checker');
    }

    public function refreshConfigurationChanges(): void
    {
        $this->configurationChanged();
    }

    /**
     * Members must never see environment variable values, so redact every
     * environment-section change before it is serialized to the browser.
     *
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<int, array<string, mixed>>
     */
    private function redactEnvironmentChanges(array $changes, bool $redact): array
    {
        if (! $redact) {
            return $changes;
        }

        return collect($changes)
            ->map(function (array $change): array {
                if (data_get($change, 'section') !== 'environment') {
                    return $change;
                }

                $change['old_display_value'] = data_get($change, 'old_display_value') === '-' ? '-' : '••••••••';
                $change['new_display_value'] = data_get($change, 'new_display_value') === '-' ? '-' : '••••••••';
                $change['old_full_value'] = null;
                $change['new_full_value'] = null;
                $change['expandable'] = false;
                $change['display_summary'] = data_get($change, 'type') === 'changed' ? 'Changed' : null;

                return $change;
            })
            ->all();
    }

    public function configurationChanged(): void
    {
        $this->resource->refresh();

        if ($this->resource instanceof Application) {
            $diff = $this->resource->pendingDeploymentConfigurationDiff();
            // Fail closed: only owners/admins may see unlocked env values.
            $redactEnvironment = ! (bool) auth()->user()?->isAdmin();

            $array = $diff->toArray();
            $array['changes'] = $this->redactEnvironmentChanges($array['changes'] ?? [], $redactEnvironment);

            $this->isConfigurationChanged = $diff->isChanged();
            $this->configurationDiff = $array;

            return;
        }

        $this->isConfigurationChanged = $this->resource->isConfigurationChanged();
        $this->configurationDiff = [];
    }
}
