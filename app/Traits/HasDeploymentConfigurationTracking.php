<?php

declare(strict_types=1);

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use App\Models\ApplicationDeploymentQueue;
use App\Services\DeploymentConfiguration\ApplicationConfigurationSnapshot;
use App\Services\DeploymentConfiguration\ConfigurationDiff;
use App\Services\DeploymentConfiguration\ConfigurationDiffer;

/**
 * Tracks whether an application's deployable configuration has drifted since
 * its last successful deployment (snapshot/diff/hash), and marks it applied
 * once a deployment picks up the current config. Extracted from
 * App\Models\Application.
 */
trait HasDeploymentConfigurationTracking
{
    public function isConfigurationChanged(bool $save = false): bool
    {
        $configurationDiff = $this->pendingDeploymentConfigurationDiff();

        if ($save) {
            $this->markDeploymentConfigurationApplied();
        }

        return $configurationDiff->isChanged();
    }

    public function pendingDeploymentConfigurationDiff(): ConfigurationDiff
    {
        $currentSnapshot = $this->deploymentConfigurationSnapshot();
        $lastDeployment = $this->get_last_successful_deployment();

        $previousSnapshot = $lastDeployment?->configuration_snapshot;

        if (! $previousSnapshot) {
            $oldConfigHash = data_get($this, 'config_hash');
            $hasLegacyChange = $oldConfigHash === null || $oldConfigHash !== $this->legacyConfigurationHash();

            if (! $hasLegacyChange) {
                return ConfigurationDiff::unchanged();
            }

            $previousSnapshot = [];
        }

        return app(ConfigurationDiffer::class)->diff($previousSnapshot, $currentSnapshot);
    }

    public function hasPendingDeploymentConfigurationChanges(): bool
    {
        return $this->pendingDeploymentConfigurationDiff()->isChanged();
    }

    /**
     * @return array<string, mixed>
     */
    public function deploymentConfigurationSnapshot(): array
    {
        return (new ApplicationConfigurationSnapshot($this))->toArray();
    }

    public function deploymentConfigurationHash(): string
    {
        return ApplicationConfigurationSnapshot::hashSnapshot($this->deploymentConfigurationSnapshot());
    }

    public function markDeploymentConfigurationApplied(?ApplicationDeploymentQueue $deployment = null): void
    {
        $this->refresh();

        if (! $deployment) {
            $this->forceFill(['config_hash' => $this->legacyConfigurationHash()])->save();

            return;
        }

        $snapshot = $this->deploymentConfigurationSnapshot();
        $hash = ApplicationConfigurationSnapshot::hashSnapshot($snapshot);

        $previousDeployment = ApplicationDeploymentQueue::query()
            ->where('application_id', $this->id)
            ->where('status', ApplicationDeploymentStatus::FINISHED->value)
            ->where('pull_request_id', $deployment->pull_request_id ?? 0)
            ->where('id', '!=', $deployment->id)
            ->whereNotNull('configuration_snapshot')
            ->latest()
            ->first();

        $deployment->update([
            'configuration_hash' => $hash,
            'configuration_snapshot' => $snapshot,
            'configuration_diff' => $previousDeployment?->configuration_snapshot
                ? app(ConfigurationDiffer::class)->diff($previousDeployment->configuration_snapshot, $snapshot)->toArray()
                : null,
        ]);

        $this->forceFill(['config_hash' => $hash])->save();
    }

    private function legacyConfigurationHash(): string
    {
        $newConfigHash = base64_encode($this->fqdn.$this->git_repository.$this->git_branch.$this->git_commit_sha.$this->build_pack.$this->static_image.$this->install_command.$this->build_command.$this->start_command.$this->ports_exposes.$this->ports_mappings.$this->custom_network_aliases.$this->base_directory.$this->publish_directory.$this->dockerfile.$this->dockerfile_location.$this->custom_labels.$this->custom_docker_run_options.$this->dockerfile_target_build.$this->redirect.$this->custom_nginx_configuration.data_get($this, 'settings.use_build_secrets').data_get($this, 'settings.inject_build_args_to_dockerfile').data_get($this, 'settings.include_source_commit_in_build'));
        $pullRequestId = data_get($this, 'pull_request_id');
        if ($pullRequestId === 0 || $pullRequestId === null) {
            $newConfigHash .= json_encode($this->environment_variables()->get(['value',  'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->sort());
        } else {
            $newConfigHash .= json_encode($this->environment_variables_preview()->get(['value', 'is_multiline', 'is_literal', 'is_buildtime', 'is_runtime'])->sort());
        }

        return md5($newConfigHash);
    }
}
