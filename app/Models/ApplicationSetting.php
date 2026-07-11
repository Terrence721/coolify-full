<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property bool $is_static
 * @property bool $is_git_submodules_enabled
 * @property bool $is_git_lfs_enabled
 * @property bool $is_auto_deploy_enabled
 * @property bool $is_force_https_enabled
 * @property bool $is_debug_enabled
 * @property bool $is_preview_deployments_enabled
 * @property int $application_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $is_log_drain_enabled
 * @property bool $is_gpu_enabled
 * @property string $gpu_driver
 * @property string|null $gpu_count
 * @property string|null $gpu_device_ids
 * @property string|null $gpu_options
 * @property bool $is_include_timestamps
 * @property bool $is_swarm_only_worker_nodes
 * @property bool $is_raw_compose_deployment_enabled
 * @property bool $is_build_server_enabled
 * @property bool $is_consistent_container_name_enabled
 * @property bool $is_gzip_enabled
 * @property bool $is_stripprefix_enabled
 * @property bool $connect_to_docker_network
 * @property string|null $custom_internal_name
 * @property bool $is_container_label_escape_enabled
 * @property bool $is_env_sorting_enabled
 * @property bool $is_container_label_readonly_enabled
 * @property bool $is_preserve_repository_enabled
 * @property bool $disable_build_cache
 * @property bool $is_spa
 * @property bool $is_git_shallow_clone_enabled
 * @property bool $is_pr_deployments_public_enabled
 * @property bool $use_build_secrets
 * @property int|null $stop_grace_period Seconds to wait for graceful shutdown before forcing container stop (1-3600). Null uses default of 30 seconds.
 * @property bool $inject_build_args_to_dockerfile
 * @property bool $include_source_commit_in_build
 * @property int $docker_images_to_keep
 * @property-read Application|null $application
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereConnectToDockerNetwork($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereCustomInternalName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereDisableBuildCache($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereDockerImagesToKeep($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereGpuCount($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereGpuDeviceIds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereGpuDriver($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereGpuOptions($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIncludeSourceCommitInBuild($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereInjectBuildArgsToDockerfile($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsAutoDeployEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsBuildServerEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsConsistentContainerNameEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsContainerLabelEscapeEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsContainerLabelReadonlyEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsDebugEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsEnvSortingEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsForceHttpsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsGitLfsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsGitShallowCloneEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsGitSubmodulesEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsGpuEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsGzipEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsIncludeTimestamps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsLogDrainEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsPrDeploymentsPublicEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsPreserveRepositoryEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsPreviewDeploymentsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsRawComposeDeploymentEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsSpa($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsStatic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsStripprefixEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereIsSwarmOnlyWorkerNodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereStopGracePeriod($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationSetting whereUseBuildSecrets($value)
 *
 * @mixin \Eloquent
 */
class ApplicationSetting extends Model
{
    protected $casts = [
        'is_static' => 'boolean',
        'is_spa' => 'boolean',
        'is_build_server_enabled' => 'boolean',
        'is_preserve_repository_enabled' => 'boolean',
        'is_container_label_escape_enabled' => 'boolean',
        'is_container_label_readonly_enabled' => 'boolean',
        'use_build_secrets' => 'boolean',
        'inject_build_args_to_dockerfile' => 'boolean',
        'include_source_commit_in_build' => 'boolean',
        'is_auto_deploy_enabled' => 'boolean',
        'is_force_https_enabled' => 'boolean',
        'is_debug_enabled' => 'boolean',
        'is_preview_deployments_enabled' => 'boolean',
        'is_pr_deployments_public_enabled' => 'boolean',
        'is_git_submodules_enabled' => 'boolean',
        'is_git_lfs_enabled' => 'boolean',
        'is_git_shallow_clone_enabled' => 'boolean',
        'is_log_drain_enabled' => 'boolean',
        'is_gpu_enabled' => 'boolean',
        'is_include_timestamps' => 'boolean',
        'is_swarm_only_worker_nodes' => 'boolean',
        'is_raw_compose_deployment_enabled' => 'boolean',
        'is_consistent_container_name_enabled' => 'boolean',
        'is_gzip_enabled' => 'boolean',
        'is_stripprefix_enabled' => 'boolean',
        'connect_to_docker_network' => 'boolean',
        'is_env_sorting_enabled' => 'boolean',
        'disable_build_cache' => 'boolean',
        'docker_images_to_keep' => 'integer',
        'stop_grace_period' => 'integer',
    ];

    protected $fillable = [
        'application_id',
        'is_static',
        'is_git_submodules_enabled',
        'is_git_lfs_enabled',
        'is_auto_deploy_enabled',
        'is_force_https_enabled',
        'is_debug_enabled',
        'is_preview_deployments_enabled',
        'is_log_drain_enabled',
        'is_gpu_enabled',
        'gpu_driver',
        'gpu_count',
        'gpu_device_ids',
        'gpu_options',
        'is_include_timestamps',
        'is_swarm_only_worker_nodes',
        'is_raw_compose_deployment_enabled',
        'is_build_server_enabled',
        'is_consistent_container_name_enabled',
        'is_gzip_enabled',
        'is_stripprefix_enabled',
        'connect_to_docker_network',
        'custom_internal_name',
        'is_container_label_escape_enabled',
        'is_env_sorting_enabled',
        'is_container_label_readonly_enabled',
        'is_preserve_repository_enabled',
        'disable_build_cache',
        'is_spa',
        'is_git_shallow_clone_enabled',
        'is_pr_deployments_public_enabled',
        'use_build_secrets',
        'inject_build_args_to_dockerfile',
        'include_source_commit_in_build',
        'docker_images_to_keep',
        'stop_grace_period',
    ];

    public function stopGracePeriodSeconds(): int
    {
        if (
            $this->stop_grace_period >= MIN_STOP_GRACE_PERIOD_SECONDS &&
            $this->stop_grace_period <= MAX_STOP_GRACE_PERIOD_SECONDS
        ) {
            return $this->stop_grace_period;
        }

        return DEFAULT_STOP_GRACE_PERIOD_SECONDS;
    }

    public function deploymentStopGracePeriodSeconds(): int
    {
        if (isDev() && $this->stop_grace_period === null) {
            return MIN_STOP_GRACE_PERIOD_SECONDS;
        }

        return $this->stopGracePeriodSeconds();
    }

    public function isStatic(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $this->application->ports_exposes = 80;
                }
                $this->application->save();

                return $value;
            }
        );
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }
}
