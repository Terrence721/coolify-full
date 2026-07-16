<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ApplicationDeploymentStatus;
use App\Services\ConfigurationGenerator;
use App\Traits\ClearsGlobalSearchCache;
use App\Traits\GeneratesGitCommands;
use App\Traits\HasConfiguration;
use App\Traits\HasDeploymentConfigurationTracking;
use App\Traits\HasMetrics;
use App\Traits\HasResourceCleanup;
use App\Traits\HasResourceLinks;
use App\Traits\HasResourceStatus;
use App\Traits\HasSafeStringAttribute;
use App\Traits\HasWatchPaths;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

/**
 * @property-read ApplicationSetting $settings
 * @property-read Environment $environment
 * @property-read StandaloneDocker|SwarmDocker|null $destination
 * @property-read Collection<int, Server> $additional_servers
 * @property-read Collection<int, StandaloneDocker> $additional_networks
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read Collection<int, LocalFileVolume> $fileStorages
 * @property-read Collection<int, EnvironmentVariable> $environment_variables
 * @property-read Collection<int, EnvironmentVariable> $environment_variables_preview
 * @property-read Collection<int, EnvironmentVariable> $runtime_environment_variables
 * @property-read Collection<int, EnvironmentVariable> $runtime_environment_variables_preview
 * @property-read Collection<int, EnvironmentVariable> $nixpacks_environment_variables
 * @property-read Collection<int, EnvironmentVariable> $nixpacks_environment_variables_preview
 * @property-read array<int, string> $ports_mappings_array
 * @property int $id
 * @property int|null $repository_project_id
 * @property string $uuid
 * @property string $name
 * @property string|null $fqdn
 * @property string|null $config_hash
 * @property string $git_repository
 * @property string $git_branch
 * @property string $git_commit_sha
 * @property string|null $git_full_url
 * @property string|null $docker_registry_image_name
 * @property string|null $docker_registry_image_tag
 * @property string $build_pack
 * @property string $static_image
 * @property string|null $install_command
 * @property string|null $build_command
 * @property string|null $start_command
 * @property string|null $ports_exposes
 * @property string|null $ports_mappings
 * @property string $base_directory
 * @property string|null $publish_directory
 * @property string $health_check_path
 * @property string|null $health_check_port
 * @property string $health_check_host
 * @property string $health_check_method
 * @property int $health_check_return_code
 * @property string $health_check_scheme
 * @property string|null $health_check_response_text
 * @property int $health_check_interval
 * @property int $health_check_timeout
 * @property int $health_check_retries
 * @property int $health_check_start_period
 * @property string $limits_memory
 * @property string $limits_memory_swap
 * @property int $limits_memory_swappiness
 * @property string $limits_memory_reservation
 * @property string $limits_cpus
 * @property string|null $limits_cpuset
 * @property int $limits_cpu_shares
 * @property string $status
 * @property string $preview_url_template
 * @property string|null $destination_type
 * @property int|null $destination_id
 * @property string|null $source_type
 * @property int|null $source_id
 * @property int|null $private_key_id
 * @property int $environment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $description
 * @property string|null $dockerfile
 * @property bool $health_check_enabled
 * @property string|null $dockerfile_location
 * @property string|null $custom_labels
 * @property string|null $dockerfile_target_build
 * @property string|null $manual_webhook_secret_github
 * @property string|null $manual_webhook_secret_gitlab
 * @property string|null $docker_compose_location
 * @property string|null $docker_compose
 * @property string|null $docker_compose_raw
 * @property string|null $docker_compose_domains
 * @property Carbon|null $deleted_at
 * @property string|null $docker_compose_custom_start_command
 * @property string|null $docker_compose_custom_build_command
 * @property int $swarm_replicas
 * @property string|null $swarm_placement_constraints
 * @property string|null $manual_webhook_secret_bitbucket
 * @property string|null $custom_docker_run_options
 * @property string|null $post_deployment_command
 * @property string|null $post_deployment_command_container
 * @property string|null $pre_deployment_command
 * @property string|null $pre_deployment_command_container
 * @property string|null $watch_paths
 * @property bool $custom_healthcheck_found
 * @property string|null $manual_webhook_secret_gitea
 * @property string $redirect
 * @property string $compose_parsing_version
 * @property string $last_online_at
 * @property string|null $custom_nginx_configuration
 * @property string|null $custom_network_aliases
 * @property bool $is_http_basic_auth_enabled
 * @property string|null $http_basic_auth_username
 * @property string|null $http_basic_auth_password
 * @property int $restart_count
 * @property Carbon|null $last_restart_at
 * @property string|null $last_restart_type
 * @property string $health_check_type
 * @property string|null $health_check_command
 * @property int $max_restart_count
 * @property-read int|null $additional_networks_count
 * @property-read int|null $additional_servers_count
 * @property-read mixed $custom_network_aliases_array
 * @property-read Collection<int, ApplicationDeploymentQueue> $deployment_queue
 * @property-read int|null $deployment_queue_count
 * @property-read int|null $environment_variables_count
 * @property-read int|null $environment_variables_preview_count
 * @property-read int|null $file_storages_count
 * @property-read mixed $fqdns
 * @property-read mixed $git_branch_location
 * @property-read mixed $git_commits
 * @property-read mixed $git_webhook
 * @property-read mixed $image
 * @property-read int|null $nixpacks_environment_variables_count
 * @property-read int|null $nixpacks_environment_variables_preview_count
 * @property-read int|null $persistent_storages_count
 * @property-read mixed $ports_exposes_array
 * @property-read Collection<int, ApplicationPreview> $previews
 * @property-read int|null $previews_count
 * @property-read PrivateKey|null $private_key
 * @property-read Collection<int, EnvironmentVariable> $railpack_environment_variables
 * @property-read int|null $railpack_environment_variables_count
 * @property-read Collection<int, EnvironmentVariable> $railpack_environment_variables_preview
 * @property-read int|null $railpack_environment_variables_preview_count
 * @property-read int|null $runtime_environment_variables_count
 * @property-read int|null $runtime_environment_variables_preview_count
 * @property-read mixed $sanitized_name
 * @property-read Collection<int, ScheduledTask> $scheduled_tasks
 * @property-read int|null $scheduled_tasks_count
 * @property-read mixed $server_status
 * @property-read Model|\Eloquent|null $source
 * @property-read Collection<int, Tag> $tags
 * @property-read int|null $tags_count
 *
 * @method static \Database\Factories\ApplicationFactory factory($count = null, $state = [])
 * @method static Builder<static>|Application newModelQuery()
 * @method static Builder<static>|Application newQuery()
 * @method static Builder<static>|Application onlyTrashed()
 * @method static Builder<static>|Application query()
 * @method static Builder<static>|Application whereBaseDirectory($value)
 * @method static Builder<static>|Application whereBuildCommand($value)
 * @method static Builder<static>|Application whereBuildPack($value)
 * @method static Builder<static>|Application whereComposeParsingVersion($value)
 * @method static Builder<static>|Application whereConfigHash($value)
 * @method static Builder<static>|Application whereCreatedAt($value)
 * @method static Builder<static>|Application whereCustomDockerRunOptions($value)
 * @method static Builder<static>|Application whereCustomHealthcheckFound($value)
 * @method static Builder<static>|Application whereCustomLabels($value)
 * @method static Builder<static>|Application whereCustomNetworkAliases($value)
 * @method static Builder<static>|Application whereCustomNginxConfiguration($value)
 * @method static Builder<static>|Application whereDeletedAt($value)
 * @method static Builder<static>|Application whereDescription($value)
 * @method static Builder<static>|Application whereDestinationId($value)
 * @method static Builder<static>|Application whereDestinationType($value)
 * @method static Builder<static>|Application whereDockerCompose($value)
 * @method static Builder<static>|Application whereDockerComposeCustomBuildCommand($value)
 * @method static Builder<static>|Application whereDockerComposeCustomStartCommand($value)
 * @method static Builder<static>|Application whereDockerComposeDomains($value)
 * @method static Builder<static>|Application whereDockerComposeLocation($value)
 * @method static Builder<static>|Application whereDockerComposeRaw($value)
 * @method static Builder<static>|Application whereDockerRegistryImageName($value)
 * @method static Builder<static>|Application whereDockerRegistryImageTag($value)
 * @method static Builder<static>|Application whereDockerfile($value)
 * @method static Builder<static>|Application whereDockerfileLocation($value)
 * @method static Builder<static>|Application whereDockerfileTargetBuild($value)
 * @method static Builder<static>|Application whereEnvironmentId($value)
 * @method static Builder<static>|Application whereFqdn($value)
 * @method static Builder<static>|Application whereGitBranch($value)
 * @method static Builder<static>|Application whereGitCommitSha($value)
 * @method static Builder<static>|Application whereGitFullUrl($value)
 * @method static Builder<static>|Application whereGitRepository($value)
 * @method static Builder<static>|Application whereHealthCheckCommand($value)
 * @method static Builder<static>|Application whereHealthCheckEnabled($value)
 * @method static Builder<static>|Application whereHealthCheckHost($value)
 * @method static Builder<static>|Application whereHealthCheckInterval($value)
 * @method static Builder<static>|Application whereHealthCheckMethod($value)
 * @method static Builder<static>|Application whereHealthCheckPath($value)
 * @method static Builder<static>|Application whereHealthCheckPort($value)
 * @method static Builder<static>|Application whereHealthCheckResponseText($value)
 * @method static Builder<static>|Application whereHealthCheckRetries($value)
 * @method static Builder<static>|Application whereHealthCheckReturnCode($value)
 * @method static Builder<static>|Application whereHealthCheckScheme($value)
 * @method static Builder<static>|Application whereHealthCheckStartPeriod($value)
 * @method static Builder<static>|Application whereHealthCheckTimeout($value)
 * @method static Builder<static>|Application whereHealthCheckType($value)
 * @method static Builder<static>|Application whereHttpBasicAuthPassword($value)
 * @method static Builder<static>|Application whereHttpBasicAuthUsername($value)
 * @method static Builder<static>|Application whereId($value)
 * @method static Builder<static>|Application whereInstallCommand($value)
 * @method static Builder<static>|Application whereIsHttpBasicAuthEnabled($value)
 * @method static Builder<static>|Application whereLastOnlineAt($value)
 * @method static Builder<static>|Application whereLastRestartAt($value)
 * @method static Builder<static>|Application whereLastRestartType($value)
 * @method static Builder<static>|Application whereLimitsCpuShares($value)
 * @method static Builder<static>|Application whereLimitsCpus($value)
 * @method static Builder<static>|Application whereLimitsCpuset($value)
 * @method static Builder<static>|Application whereLimitsMemory($value)
 * @method static Builder<static>|Application whereLimitsMemoryReservation($value)
 * @method static Builder<static>|Application whereLimitsMemorySwap($value)
 * @method static Builder<static>|Application whereLimitsMemorySwappiness($value)
 * @method static Builder<static>|Application whereManualWebhookSecretBitbucket($value)
 * @method static Builder<static>|Application whereManualWebhookSecretGitea($value)
 * @method static Builder<static>|Application whereManualWebhookSecretGithub($value)
 * @method static Builder<static>|Application whereManualWebhookSecretGitlab($value)
 * @method static Builder<static>|Application whereMaxRestartCount($value)
 * @method static Builder<static>|Application whereName($value)
 * @method static Builder<static>|Application wherePortsExposes($value)
 * @method static Builder<static>|Application wherePortsMappings($value)
 * @method static Builder<static>|Application wherePostDeploymentCommand($value)
 * @method static Builder<static>|Application wherePostDeploymentCommandContainer($value)
 * @method static Builder<static>|Application wherePreDeploymentCommand($value)
 * @method static Builder<static>|Application wherePreDeploymentCommandContainer($value)
 * @method static Builder<static>|Application wherePreviewUrlTemplate($value)
 * @method static Builder<static>|Application wherePrivateKeyId($value)
 * @method static Builder<static>|Application wherePublishDirectory($value)
 * @method static Builder<static>|Application whereRedirect($value)
 * @method static Builder<static>|Application whereRepositoryProjectId($value)
 * @method static Builder<static>|Application whereRestartCount($value)
 * @method static Builder<static>|Application whereSourceId($value)
 * @method static Builder<static>|Application whereSourceType($value)
 * @method static Builder<static>|Application whereStartCommand($value)
 * @method static Builder<static>|Application whereStaticImage($value)
 * @method static Builder<static>|Application whereStatus($value)
 * @method static Builder<static>|Application whereSwarmPlacementConstraints($value)
 * @method static Builder<static>|Application whereSwarmReplicas($value)
 * @method static Builder<static>|Application whereUpdatedAt($value)
 * @method static Builder<static>|Application whereUuid($value)
 * @method static Builder<static>|Application whereWatchPaths($value)
 * @method static Builder<static>|Application withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Application withoutTrashed()
 *
 * @property-read AdditionalDestinationPivot|null $pivot
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Application model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The application identifier in the database.'),
        new OA\Property(property: 'description', type: 'string', nullable: true, description: 'The application description.'),
        new OA\Property(property: 'repository_project_id', type: 'integer', nullable: true, description: 'The repository project identifier.'),
        new OA\Property(property: 'uuid', type: 'string', description: 'The application UUID.'),
        new OA\Property(property: 'name', type: 'string', description: 'The application name.'),
        new OA\Property(property: 'fqdn', type: 'string', nullable: true, description: 'The application domains.'),
        new OA\Property(property: 'config_hash', type: 'string', description: 'Configuration hash.'),
        new OA\Property(property: 'git_repository', type: 'string', description: 'Git repository URL.'),
        new OA\Property(property: 'git_branch', type: 'string', description: 'Git branch.'),
        new OA\Property(property: 'git_commit_sha', type: 'string', description: 'Git commit SHA.'),
        new OA\Property(property: 'git_full_url', type: 'string', nullable: true, description: 'Git full URL.'),
        new OA\Property(property: 'docker_registry_image_name', type: 'string', nullable: true, description: 'Docker registry image name.'),
        new OA\Property(property: 'docker_registry_image_tag', type: 'string', nullable: true, description: 'Docker registry image tag.'),
        new OA\Property(property: 'build_pack', type: 'string', description: 'Build pack.', enum: ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose']),
        new OA\Property(property: 'static_image', type: 'string', description: 'Static image used when static site is deployed.'),
        new OA\Property(property: 'install_command', type: 'string', description: 'Install command.'),
        new OA\Property(property: 'build_command', type: 'string', description: 'Build command.'),
        new OA\Property(property: 'start_command', type: 'string', description: 'Start command.'),
        new OA\Property(property: 'ports_exposes', type: 'string', description: 'Ports exposes.'),
        new OA\Property(property: 'ports_mappings', type: 'string', nullable: true, description: 'Ports mappings.'),
        new OA\Property(property: 'custom_network_aliases', type: 'string', nullable: true, description: 'Network aliases for Docker container.'),
        new OA\Property(property: 'base_directory', type: 'string', description: 'Base directory for all commands.'),
        new OA\Property(property: 'publish_directory', type: 'string', description: 'Publish directory.'),
        new OA\Property(property: 'health_check_enabled', type: 'boolean', description: 'Health check enabled.'),
        new OA\Property(property: 'health_check_path', type: 'string', description: 'Health check path.'),
        new OA\Property(property: 'health_check_port', type: 'string', nullable: true, description: 'Health check port.'),
        new OA\Property(property: 'health_check_host', type: 'string', nullable: true, description: 'Health check host.'),
        new OA\Property(property: 'health_check_method', type: 'string', description: 'Health check method.'),
        new OA\Property(property: 'health_check_return_code', type: 'integer', description: 'Health check return code.'),
        new OA\Property(property: 'health_check_scheme', type: 'string', description: 'Health check scheme.'),
        new OA\Property(property: 'health_check_response_text', type: 'string', nullable: true, description: 'Health check response text.'),
        new OA\Property(property: 'health_check_interval', type: 'integer', description: 'Health check interval in seconds.'),
        new OA\Property(property: 'health_check_timeout', type: 'integer', description: 'Health check timeout in seconds.'),
        new OA\Property(property: 'health_check_retries', type: 'integer', description: 'Health check retries count.'),
        new OA\Property(property: 'health_check_start_period', type: 'integer', description: 'Health check start period in seconds.'),
        new OA\Property(property: 'health_check_type', type: 'string', description: 'Health check type: http or cmd.', enum: ['http', 'cmd']),
        new OA\Property(property: 'health_check_command', type: 'string', nullable: true, description: 'Health check command for CMD type.'),
        new OA\Property(property: 'limits_memory', type: 'string', description: 'Memory limit.'),
        new OA\Property(property: 'limits_memory_swap', type: 'string', description: 'Memory swap limit.'),
        new OA\Property(property: 'limits_memory_swappiness', type: 'integer', description: 'Memory swappiness.'),
        new OA\Property(property: 'limits_memory_reservation', type: 'string', description: 'Memory reservation.'),
        new OA\Property(property: 'limits_cpus', type: 'string', description: 'CPU limit.'),
        new OA\Property(property: 'limits_cpuset', type: 'string', nullable: true, description: 'CPU set.'),
        new OA\Property(property: 'limits_cpu_shares', type: 'integer', description: 'CPU shares.'),
        new OA\Property(property: 'status', type: 'string', description: 'Application status.'),
        new OA\Property(property: 'preview_url_template', type: 'string', description: 'Preview URL template.'),
        new OA\Property(property: 'destination_type', type: 'string', description: 'Destination type.'),
        new OA\Property(property: 'destination_id', type: 'integer', description: 'Destination identifier.'),
        new OA\Property(property: 'source_id', type: 'integer', nullable: true, description: 'Source identifier.'),
        new OA\Property(property: 'private_key_id', type: 'integer', nullable: true, description: 'Private key identifier.'),
        new OA\Property(property: 'environment_id', type: 'integer', description: 'Environment identifier.'),
        new OA\Property(property: 'dockerfile', type: 'string', nullable: true, description: 'Dockerfile content. Used for dockerfile build pack.'),
        new OA\Property(property: 'dockerfile_location', type: 'string', description: 'Dockerfile location.'),
        new OA\Property(property: 'custom_labels', type: 'string', nullable: true, description: 'Custom labels.'),
        new OA\Property(property: 'dockerfile_target_build', type: 'string', nullable: true, description: 'Dockerfile target build.'),
        new OA\Property(property: 'manual_webhook_secret_github', type: 'string', nullable: true, description: 'Manual webhook secret for GitHub.'),
        new OA\Property(property: 'manual_webhook_secret_gitlab', type: 'string', nullable: true, description: 'Manual webhook secret for GitLab.'),
        new OA\Property(property: 'manual_webhook_secret_bitbucket', type: 'string', nullable: true, description: 'Manual webhook secret for Bitbucket.'),
        new OA\Property(property: 'manual_webhook_secret_gitea', type: 'string', nullable: true, description: 'Manual webhook secret for Gitea.'),
        new OA\Property(property: 'docker_compose_location', type: 'string', description: 'Docker compose location.'),
        new OA\Property(property: 'docker_compose', type: 'string', nullable: true, description: 'Docker compose content. Used for docker compose build pack.'),
        new OA\Property(property: 'docker_compose_raw', type: 'string', nullable: true, description: 'Docker compose raw content.'),
        new OA\Property(property: 'docker_compose_domains', type: 'string', nullable: true, description: 'Docker compose domains.'),
        new OA\Property(property: 'docker_compose_custom_start_command', type: 'string', nullable: true, description: 'Docker compose custom start command.'),
        new OA\Property(property: 'docker_compose_custom_build_command', type: 'string', nullable: true, description: 'Docker compose custom build command.'),
        new OA\Property(property: 'swarm_replicas', type: 'integer', nullable: true, description: 'Swarm replicas. Only used for swarm deployments.'),
        new OA\Property(property: 'swarm_placement_constraints', type: 'string', nullable: true, description: 'Swarm placement constraints. Only used for swarm deployments.'),
        new OA\Property(property: 'custom_docker_run_options', type: 'string', nullable: true, description: 'Custom docker run options.'),
        new OA\Property(property: 'post_deployment_command', type: 'string', nullable: true, description: 'Post deployment command.'),
        new OA\Property(property: 'post_deployment_command_container', type: 'string', nullable: true, description: 'Post deployment command container.'),
        new OA\Property(property: 'pre_deployment_command', type: 'string', nullable: true, description: 'Pre deployment command.'),
        new OA\Property(property: 'pre_deployment_command_container', type: 'string', nullable: true, description: 'Pre deployment command container.'),
        new OA\Property(property: 'watch_paths', type: 'string', nullable: true, description: 'Watch paths.'),
        new OA\Property(property: 'custom_healthcheck_found', type: 'boolean', description: 'Custom healthcheck found.'),
        new OA\Property(property: 'redirect', type: 'string', nullable: true, description: 'How to set redirect with Traefik / Caddy. www<->non-www.', enum: ['www', 'non-www', 'both']),
        new OA\Property(property: 'created_at', type: 'string', format: 'date-time', description: 'The date and time when the application was created.'),
        new OA\Property(property: 'updated_at', type: 'string', format: 'date-time', description: 'The date and time when the application was last updated.'),
        new OA\Property(property: 'deleted_at', type: 'string', format: 'date-time', nullable: true, description: 'The date and time when the application was deleted.'),
        new OA\Property(property: 'compose_parsing_version', type: 'string', description: 'How Coolify parse the compose file.'),
        new OA\Property(property: 'custom_nginx_configuration', type: 'string', nullable: true, description: 'Custom Nginx configuration base64 encoded.'),
        new OA\Property(property: 'is_http_basic_auth_enabled', type: 'boolean', description: 'HTTP Basic Authentication enabled.'),
        new OA\Property(property: 'http_basic_auth_username', type: 'string', nullable: true, description: 'Username for HTTP Basic Authentication'),
        new OA\Property(property: 'http_basic_auth_password', type: 'string', nullable: true, description: 'Password for HTTP Basic Authentication'),
    ]
)]
class Application extends BaseModel
{
    use ClearsGlobalSearchCache, GeneratesGitCommands, HasConfiguration, HasDeploymentConfigurationTracking, HasFactory, HasMetrics, HasResourceCleanup, HasResourceLinks, HasResourceStatus, HasSafeStringAttribute, HasWatchPaths, SoftDeletes;

    protected function resourceTypeSlug(): string
    {
        return 'application';
    }

    private static string $parserVersion = '5';

    protected $fillable = [
        'name',
        'description',
        'fqdn',
        'git_repository',
        'git_branch',
        'git_commit_sha',
        'git_full_url',
        'docker_registry_image_name',
        'docker_registry_image_tag',
        'build_pack',
        'static_image',
        'install_command',
        'build_command',
        'start_command',
        'ports_exposes',
        'ports_mappings',
        'base_directory',
        'publish_directory',
        'health_check_enabled',
        'health_check_path',
        'health_check_port',
        'health_check_host',
        'health_check_method',
        'health_check_return_code',
        'health_check_scheme',
        'health_check_response_text',
        'health_check_interval',
        'health_check_timeout',
        'health_check_retries',
        'health_check_start_period',
        'health_check_type',
        'health_check_command',
        'limits_memory',
        'limits_memory_swap',
        'limits_memory_swappiness',
        'limits_memory_reservation',
        'limits_cpus',
        'limits_cpuset',
        'limits_cpu_shares',
        'status',
        'preview_url_template',
        'dockerfile',
        'dockerfile_location',
        'dockerfile_target_build',
        'custom_labels',
        'custom_docker_run_options',
        'post_deployment_command',
        'post_deployment_command_container',
        'pre_deployment_command',
        'pre_deployment_command_container',
        'manual_webhook_secret_github',
        'manual_webhook_secret_gitlab',
        'manual_webhook_secret_bitbucket',
        'manual_webhook_secret_gitea',
        'docker_compose_location',
        'docker_compose_pr_location',
        'docker_compose',
        'docker_compose_pr',
        'docker_compose_raw',
        'docker_compose_pr_raw',
        'docker_compose_domains',
        'docker_compose_custom_start_command',
        'docker_compose_custom_build_command',
        'swarm_replicas',
        'swarm_placement_constraints',
        'watch_paths',
        'redirect',
        'compose_parsing_version',
        'custom_nginx_configuration',
        'custom_network_aliases',
        'custom_healthcheck_found',
        'nixpkgsarchive',
        'is_http_basic_auth_enabled',
        'http_basic_auth_username',
        'http_basic_auth_password',
        'connect_to_docker_network',
        'force_domain_override',
        'is_container_label_escape_enabled',
        'use_build_server',
        'config_hash',
        'last_online_at',
        'restart_count',
        'max_restart_count',
        'last_restart_at',
        'last_restart_type',
        'uuid',
        'environment_id',
        'destination_id',
        'destination_type',
        'source_id',
        'source_type',
        'repository_project_id',
        'private_key_id',
    ];

    protected $appends = ['server_status'];

    protected function casts(): array
    {
        return [
            'http_basic_auth_password' => 'encrypted',
            'manual_webhook_secret_github' => 'encrypted',
            'manual_webhook_secret_gitlab' => 'encrypted',
            'manual_webhook_secret_bitbucket' => 'encrypted',
            'manual_webhook_secret_gitea' => 'encrypted',
            'restart_count' => 'integer',
            'max_restart_count' => 'integer',
            'last_restart_at' => 'datetime',
            'is_http_basic_auth_enabled' => 'boolean',
            'health_check_enabled' => 'boolean',
            'custom_healthcheck_found' => 'boolean',
        ];
    }

    protected static function booted()
    {
        static::creating(function ($application) {
            $application->manual_webhook_secret_github ??= Str::random(40);
            $application->manual_webhook_secret_gitlab ??= Str::random(40);
            $application->manual_webhook_secret_bitbucket ??= Str::random(40);
            $application->manual_webhook_secret_gitea ??= Str::random(40);
        });
        static::addGlobalScope('withRelations', function ($builder) {
            $builder->withCount([
                'additional_servers',
                'additional_networks',
            ]);
        });
        static::saving(function ($application) {
            $payload = [];
            if ($application->isDirty('fqdn')) {
                if ($application->fqdn === '') {
                    $application->fqdn = null;
                }
                $payload['fqdn'] = $application->fqdn;
            }
            if ($application->isDirty('install_command')) {
                $payload['install_command'] = str($application->install_command)->trim()->toString();
            }
            if ($application->isDirty('build_command')) {
                $payload['build_command'] = str($application->build_command)->trim()->toString();
            }
            if ($application->isDirty('start_command')) {
                $payload['start_command'] = str($application->start_command)->trim()->toString();
            }
            if ($application->isDirty('base_directory')) {
                $payload['base_directory'] = str($application->base_directory)->trim()->toString();
            }
            if ($application->isDirty('publish_directory')) {
                $payload['publish_directory'] = str($application->publish_directory)->trim()->toString();
            }
            if ($application->isDirty('git_repository')) {
                $payload['git_repository'] = str($application->git_repository)->trim()->toString();
            }
            if ($application->isDirty('git_branch')) {
                $payload['git_branch'] = str($application->git_branch)->trim()->toString();
            }
            if ($application->isDirty('git_commit_sha')) {
                $payload['git_commit_sha'] = str($application->git_commit_sha)->trim()->toString();
            }
            if ($application->isDirty('status')) {
                $payload['last_online_at'] = now();
            }
            if ($application->isDirty('custom_nginx_configuration')) {
                if ($application->custom_nginx_configuration === '') {
                    $payload['custom_nginx_configuration'] = null;
                }
            }
            if (count($payload) > 0) {
                $application->fill($payload);
            }

            // Buildpack switching cleanup logic
            if ($application->isDirty('build_pack')) {
                $originalBuildPack = $application->getOriginal('build_pack');

                // Clear Docker Compose specific data when switching away from dockercompose
                if ($originalBuildPack === 'dockercompose') {
                    $application->docker_compose_domains = null;
                    $application->docker_compose_raw = null;

                    // Remove SERVICE_FQDN_* and SERVICE_URL_* environment variables
                    $application->environment_variables()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                    $application->environment_variables_preview()
                        ->where(function ($q) {
                            $q->where('key', 'LIKE', 'SERVICE_FQDN_%')
                                ->orWhere('key', 'LIKE', 'SERVICE_URL_%');
                        })
                        ->delete();
                }

                // Clear Dockerfile specific data when switching away from dockerfile
                if ($originalBuildPack === 'dockerfile') {
                    $application->dockerfile = null;
                    $application->dockerfile_location = null;
                    $application->dockerfile_target_build = null;
                    $application->custom_healthcheck_found = false;
                }
            }
        });
        static::created(function ($application) {
            ApplicationSetting::create([
                'application_id' => $application->id,
            ]);
            $application->compose_parsing_version = self::$parserVersion;
            $application->save();

            // Add default NIXPACKS_NODE_VERSION environment variable for Nixpacks applications
            if ($application->build_pack === 'nixpacks') {
                EnvironmentVariable::create([
                    'key' => 'NIXPACKS_NODE_VERSION',
                    'value' => '22',
                    'is_multiline' => false,
                    'is_literal' => false,
                    'is_buildtime' => true,
                    'is_runtime' => false,
                    'is_preview' => false,
                    'resourceable_type' => Application::class,
                    'resourceable_id' => $application->id,
                ]);
            }
        });
        static::forceDeleting(function ($application) {
            $application->update(['fqdn' => null]);
            $application->settings()->delete();
            $application->persistentStorages()->delete();
            $application->environment_variables()->delete();
            $application->environment_variables_preview()->delete();
            foreach ($application->scheduled_tasks as $task) {
                $task->delete();
            }
            $application->tags()->detach();
            $application->previews()->delete();
            foreach ($application->deployment_queue as $deployment) {
                $deployment->delete();
            }
        });
    }

    /**
     * @return Attribute<Collection<int, mixed>, never>
     */
    public function customNetworkAliases(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return null;
                }

                // If it's already a JSON string, decode it
                if (is_string($value) && $this->isJson($value)) {
                    $value = json_decode($value, true);
                }

                // If it's a string but not JSON, treat it as a comma-separated list
                if (is_string($value) && ! is_array($value)) {
                    $value = explode(',', $value);
                }

                if (! is_array($value)) {
                    $value = [];
                }

                $value = collect($value)
                    ->map(function ($alias) {
                        if (is_string($alias)) {
                            return str_replace(' ', '-', trim($alias));
                        }

                        return null;
                    })
                    ->filter()
                    ->unique() // Remove duplicate values
                    ->values()
                    ->toArray();

                return empty($value) ? null : json_encode($value);
            },
            get: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    $decoded = json_decode($value, true);

                    // Return as comma-separated string, not array
                    return is_array($decoded) ? implode(',', $decoded) : $value;
                }

                return $value;
            }
        );
    }

    /**
     * Get custom_network_aliases as an array
     * @return Attribute<array<int, string>, never>
     */
    public function customNetworkAliasesArray(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('custom_network_aliases');
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    return json_decode($value, true);
                }

                return is_array($value) ? $value : [];
            }
        );
    }

    /**
     * Check if a string is a valid JSON
     */
    private function isJson(string $string): bool
    {
        json_decode($string);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeamAPI(int|string $teamId): Builder
    {
        return Application::whereRelation('environment.project.team', 'id', $teamId)->orderBy('name');
    }

    /**
     * Get query builder for applications owned by current team.
     * If you need all applications without further query chaining, use ownedByCurrentTeamCached() instead.
     */
    /**
     * @return Builder<self>
     */
    public static function ownedByCurrentTeam(): Builder
    {
        $team = currentTeam();
        if (! $team) {
            return Application::whereRaw('1 = 0');
        }

        return Application::whereRelation('environment.project.team', 'id', $team->id)->orderBy('name');
    }

    /**
     * Get all applications owned by current team (cached for request duration).
     */
    /**
     * @return Collection<int, Application>
     */
    public static function ownedByCurrentTeamCached(): Collection
    {
        return once(function () {
            return Application::ownedByCurrentTeam()->get();
        });
    }

    public function getContainersToStop(Server $server, bool $previewDeployments = false): array
    {
        $containers = $previewDeployments
            ? getCurrentApplicationContainerStatus($server, $this->id, includePullrequests: true)
            : getCurrentApplicationContainerStatus($server, $this->id, 0);

        return $containers->pluck('Names')->toArray();
    }

    public function deleteVolumes(): void
    {
        $persistentStorages = $this->persistentStorages()->get();
        if ($this->build_pack === 'dockercompose') {
            $server = data_get($this, 'destination.server');
            instant_remote_process(["cd {$this->dirOnServer()} && docker compose down -v"], $server, false);
        } else {
            if ($persistentStorages->count() === 0) {
                return;
            }
            $server = data_get($this, 'destination.server');
            foreach ($persistentStorages as $storage) {
                instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
            }
        }
    }

    /**
     * @return BelongsToMany<Server, $this, AdditionalDestinationPivot>
     */
    public function additional_servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'additional_destinations')
            ->using(AdditionalDestinationPivot::class)
            ->withPivot('standalone_docker_id', 'status');
    }

    /**
     * @return BelongsToMany<StandaloneDocker, $this, Pivot>
     */
    public function additional_networks(): BelongsToMany
    {
        return $this->belongsToMany(StandaloneDocker::class, 'additional_destinations')
            ->withPivot('server_id', 'status');
    }

    public function is_public_repository(): bool
    {
        if (data_get($this, 'source.is_public')) {
            return true;
        }

        return false;
    }

    public function is_github_based(): bool
    {
        if (data_get($this, 'source')) {
            return true;
        }

        return false;
    }

    public function isForceHttpsEnabled(): bool
    {
        return (bool) data_get($this, 'settings.is_force_https_enabled', false);
    }

    public function isStripprefixEnabled(): bool
    {
        return (bool) data_get($this, 'settings.is_stripprefix_enabled', true);
    }

    public function isGzipEnabled(): bool
    {
        return (bool) data_get($this, 'settings.is_gzip_enabled', true);
    }

    public function stoppedAfterRestartLimit(): bool
    {
        return str($this->status)->startsWith('exited')
            && $this->restart_count > 0
            && $this->max_restart_count > 0
            && $this->restart_count >= $this->max_restart_count
            && $this->last_restart_type === 'crash';
    }

    /**
     * @return HasOne<ApplicationSetting, $this>
     */
    public function settings(): HasOne
    {
        return $this->hasOne(ApplicationSetting::class);
    }

    /**
     * @return MorphMany<LocalPersistentVolume, $this>
     */
    public function persistentStorages(): MorphMany
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    /**
     * @return MorphMany<LocalFileVolume, $this>
     */
    public function fileStorages(): MorphMany
    {
        return $this->morphMany(LocalFileVolume::class, 'resource');
    }

    public function type(): string
    {
        return 'application';
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    public function publishDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value ? '/'.ltrim($value, '/') : null,
        );
    }

    /**
     * @return Attribute<string, never>
     */
    public function gitBranchLocation(): Attribute
    {
        return Attribute::make(
            get: function () {
                $base_dir = $this->base_directory;
                $sourceHtmlUrl = data_get($this, 'source.html_url');
                if (! is_null($sourceHtmlUrl)) {
                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "{$sourceHtmlUrl}/{$this->git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "{$sourceHtmlUrl}/{$this->git_repository}/tree/{$this->git_branch}{$base_dir}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    if (str($this->git_repository)->contains('bitbucket')) {
                        return "https://{$git_repository}/src/{$this->git_branch}{$base_dir}";
                    }

                    return "https://{$git_repository}/tree/{$this->git_branch}{$base_dir}";
                }

                return $this->git_repository;
            }
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function gitWebhook(): Attribute
    {
        return Attribute::make(
            get: function () {
                $sourceHtmlUrl = data_get($this, 'source.html_url');
                if (! is_null($sourceHtmlUrl)) {
                    return "{$sourceHtmlUrl}/{$this->git_repository}/settings/hooks";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/settings/hooks";
                }

                return $this->git_repository;
            }
        );
    }

    /**
     * @return Attribute<array<int, mixed>, never>
     */
    public function gitCommits(): Attribute
    {
        return Attribute::make(
            get: function () {
                $sourceHtmlUrl = data_get($this, 'source.html_url');
                if (! is_null($sourceHtmlUrl)) {
                    return "{$sourceHtmlUrl}/{$this->git_repository}/commits/{$this->git_branch}";
                }
                // Convert the SSH URL to HTTPS URL
                if (strpos($this->git_repository, 'git@') === 0) {
                    $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);

                    return "https://{$git_repository}/commits/{$this->git_branch}";
                }

                return $this->git_repository;
            }
        );
    }

    public function gitCommitLink(string $link): string
    {
        $sourceHtmlUrl = data_get($this, 'source.html_url');
        if (! is_null($sourceHtmlUrl)) {
            if (str($sourceHtmlUrl)->contains('bitbucket')) {
                return "{$sourceHtmlUrl}/{$this->git_repository}/commits/{$link}";
            }

            return "{$sourceHtmlUrl}/{$this->git_repository}/commit/{$link}";
        }
        if (str($this->git_repository)->contains('bitbucket')) {
            $git_repository = str_replace('.git', '', $this->git_repository);
            $url = Url::fromString($git_repository);
            $url = $url->withUserInfo('');
            $url = $url->withPath($url->getPath().'/commits/'.$link);

            return $url->__toString();
        }
        if (strpos($this->git_repository, 'git@') === 0) {
            $git_repository = str_replace(['git@', ':', '.git'], ['', '/', ''], $this->git_repository);
            return "{$git_repository}/commit/{$link}";
        }

        return $this->git_repository;
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function dockerfileLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return $this->build_pack === 'dockerfile' ? '/Dockerfile' : null;
                }

                if ($value !== '/') {
                    return Str::start(Str::replaceEnd('/', '', $value), '/');
                }

                return Str::start($value, '/');
            }
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function dockerComposeLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/docker-compose.yaml';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }

                    return Str::start($value, '/');
                }
            }
        );
    }

    /**
     * @return Attribute<string, string>
     */
    public function baseDirectory(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => '/'.ltrim($value, '/'),
        );
    }

    /**
     * @return Attribute<string|null, never>
     */
    public function portsMappings(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => $value === '' ? null : $value,
        );
    }

    /**
     * @return Attribute<array<int, string>, never>
     */
    public function portsMappingsArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_mappings)
                ? []
                : explode(',', $this->ports_mappings),

        );
    }

    public function realStatus(): mixed
    {
        return $this->getRawOriginal('status');
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                // Check main server infrastructure health
                $mainServer = data_get($this, 'destination.server');
                $main_server_functional = $mainServer?->isFunctional() ?? false;

                if (! $main_server_functional) {
                    return false;
                }

                // Check additional servers infrastructure health (not container status!)
                $additionalServers = $this->additional_servers()->get();
                if ($additionalServers->count() > 0) {
                    foreach ($additionalServers as $server) {
                        if (! $server->isFunctional()) {
                            return false;  // Real server infrastructure problem
                        }
                    }
                }

                return true;
            }
        );
    }

    /**
     * @return Attribute<string, string>
     */
    public function status(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                $additionalServers = $this->additional_servers()->get();
                if ($additionalServers->count() === 0) {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value();
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value();
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value();
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value();
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                }
            },
            get: function ($value) {
                $additionalServers = $this->additional_servers()->get();
                if ($additionalServers->count() === 0) {
                    // running (healthy)
                    if (str($value)->contains('(')) {
                        $status = str($value)->before('(')->trim()->value();
                        $health = str($value)->after('(')->before(')')->trim()->value();
                    } elseif (str($value)->contains(':')) {
                        $status = str($value)->before(':')->trim()->value();
                        $health = str($value)->after(':')->trim()->value();
                    } else {
                        $status = $value;
                        $health = 'unhealthy';
                    }

                    return "$status:$health";
                } else {
                    $complex_status = null;
                    $complex_health = null;
                    $complex_status = $main_server_status = str($value)->before(':')->value();
                    $complex_health = $main_server_health = str($value)->after(':')->value();
                    $additional_servers_status = $additionalServers->pluck('pivot.status');
                    foreach ($additional_servers_status as $status) {
                        $server_status = str($status)->before(':')->value();
                        $server_health = str($status)->after(':')->value();
                        if ($main_server_status !== $server_status) {
                            $complex_status = 'degraded';
                        }
                        if ($main_server_health !== $server_health) {
                            $complex_health = 'unhealthy';
                        }
                    }

                    return "$complex_status:$complex_health";
                }
            },
        );
    }

    /**
     * @return Attribute<?string, ?string>
     */
    public function customNginxConfiguration(): Attribute
    {
        return Attribute::make(
            set: fn ($value) => is_null($value) ? null : base64_encode($value),
            get: fn ($value) => is_null($value) ? null : base64_decode($value),
        );
    }

    /**
     * @return Attribute<array<int, string>, never>
     */
    public function portsExposesArray(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->ports_exposes)
                ? []
                : explode(',', $this->ports_exposes)
        );
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function project(): ?Project
    {
        return data_get($this, 'environment.project');
    }

    public function team(): ?Team
    {
        return data_get($this, 'environment.project.team');
    }

    public function serviceType(): ?string
    {
        $image = (string) data_get($this, 'image', '');
        $found = collect(SPECIFIC_SERVICES)->filter(function ($service) use ($image) {
            return str($image)->before(':')->value() === $service;
        })->first();
        if (is_string($found) && $found !== '') {
            return $found;
        }

        return null;
    }

    /**
     * @return array<int, int|string>
     */
    public function main_port(): array
    {
        $isStatic = (bool) data_get($this, 'settings.is_static', false);
        $portsExposes = data_get($this, 'ports_exposes');

        return $isStatic ? [80] : (is_null($portsExposes) ? [] : explode(',', $portsExposes));
    }

    public function detectPortFromEnvironment(?bool $isPreview = false): ?int
    {
        $envVars = $isPreview
            ? $this->environment_variables_preview()->get()
            : $this->environment_variables()->get();

        $portVar = $envVars->firstWhere('key', 'PORT');

        if ($portVar && $portVar->real_value) {
            $portValue = trim($portVar->real_value);
            if (is_numeric($portValue)) {
                return (int) $portValue;
            }
        }

        return null;
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false);
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function runtime_environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->withoutBuildpackControlVariables();
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function nixpacks_environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function railpack_environment_variables(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', false)
            ->where('key', 'like', 'RAILPACK_%');
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function environment_variables_preview(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->orderByRaw("
                CASE
                    WHEN is_required = true THEN 1
                    WHEN LOWER(key) LIKE 'service_%' THEN 2
                    ELSE 3
                END,
                LOWER(key) ASC
            ");
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function runtime_environment_variables_preview(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->withoutBuildpackControlVariables();
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function nixpacks_environment_variables_preview(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'NIXPACKS_%');
    }

    /**
     * @return MorphMany<EnvironmentVariable, $this>
     */
    public function railpack_environment_variables_preview(): MorphMany
    {
        return $this->morphMany(EnvironmentVariable::class, 'resourceable')
            ->where('is_preview', true)
            ->where('key', 'like', 'RAILPACK_%');
    }

    /**
     * @return HasMany<ScheduledTask, $this>
     */
    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    /**
     * @return BelongsTo<PrivateKey, $this>
     */
    public function private_key(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    /**
     * @return BelongsTo<Environment, $this>
     */
    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    /**
     * @return HasMany<ApplicationPreview, $this>
     */
    public function previews(): HasMany
    {
        return $this->hasMany(ApplicationPreview::class)->orderBy('pull_request_id', 'desc');
    }

    /**
     * @return HasMany<ApplicationDeploymentQueue, $this>
     */
    public function deployment_queue(): HasMany
    {
        return $this->hasMany(ApplicationDeploymentQueue::class);
    }

    /**
     * @return MorphTo<StandaloneDocker|SwarmDocker, Application>
     */
    public function destination(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<GithubApp|GitlabApp, Application>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function isDeploymentInprogress(): bool
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS, ApplicationDeploymentStatus::QUEUED])->count();
        if ($deployments > 0) {
            return true;
        }

        return false;
    }

    public function get_last_successful_deployment(): ?ApplicationDeploymentQueue
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('status', ApplicationDeploymentStatus::FINISHED->value)->where('pull_request_id', 0)->orderBy('created_at', 'desc')->first();
    }

    /**
     * @return Collection<int, ApplicationDeploymentQueue>
     */
    public function get_last_days_deployments(): Collection
    {
        return ApplicationDeploymentQueue::where('application_id', $this->id)->where('created_at', '>=', now()->subDays(7))->orderBy('created_at', 'desc')->get();
    }

    /**
     * @return array{count: int, deployments: Collection<int, ApplicationDeploymentQueue>}
     */
    public function deployments(int $skip = 0, int $take = 10, ?string $pullRequestId = null): array
    {
        $deployments = ApplicationDeploymentQueue::where('application_id', $this->id)->orderBy('created_at', 'desc');

        if ($pullRequestId) {
            $deployments = $deployments->where('pull_request_id', $pullRequestId);
        }

        $count = $deployments->count();
        $deployments = $deployments->skip($skip)->take($take)->get();

        return [
            'count' => $count,
            'deployments' => $deployments,
        ];
    }

    public function get_deployment(string $deployment_uuid): ?Activity
    {
        return Activity::where('subject_id', $this->id)->where('properties->type_uuid', '=', $deployment_uuid)->first();
    }

    public function isDeployable(): bool
    {
        if (data_get($this, 'settings.is_auto_deploy_enabled')) {
            return true;
        }

        return false;
    }

    public function isPRDeployable(): bool
    {
        if (data_get($this, 'settings.is_preview_deployments_enabled')) {
            return true;
        }

        return false;
    }

    public function deploymentType(): string
    {
        $privateKeyId = data_get($this, 'private_key_id');

        // Real private key (id > 0) always takes precedence
        if ($privateKeyId !== null && $privateKeyId > 0) {
            return 'deploy_key';
        }

        // GitHub/GitLab App source
        if (data_get($this, 'source')) {
            return 'source';
        }

        // Localhost key (id = 0) when no source is configured
        if ($privateKeyId === 0) {
            return 'deploy_key';
        }

        return 'other';
    }

    public function could_set_build_commands(): bool
    {
        if ($this->build_pack === 'nixpacks' || $this->build_pack === 'railpack') {
            return true;
        }

        return false;
    }

    public function git_based(): bool
    {
        if ($this->dockerfile) {
            return false;
        }
        if ($this->build_pack === 'dockerimage') {
            return false;
        }

        return true;
    }

    public function isHealthcheckDisabled(): bool
    {
        if (data_get($this, 'health_check_enabled') === false) {
            return true;
        }

        return false;
    }

    public function workdir(): string
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function isLogDrainEnabled(): bool
    {
        return (bool) data_get($this, 'settings.is_log_drain_enabled', false);
    }

    public function generateBaseDir(string $uuid): string
    {
        return "/artifacts/{$uuid}";
    }

    public function dirOnServer(): string
    {
        return application_configuration_dir()."/{$this->uuid}";
    }

    public function parse(int $pull_request_id = 0, ?int $preview_id = null, ?string $commit = null): mixed
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return applicationParser($this, $pull_request_id, $preview_id, $commit);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile(resource: $this, isNew: false, pull_request_id: $pull_request_id, preview_id: $preview_id);
        } else {
            return collect([]);
        }
    }

    /**
     * @return array{parsedServices: mixed, initialDockerComposeLocation: ?string}|null
     */
    public function loadComposeFile(bool $isInit = false, ?string $restoreBaseDirectory = null, ?string $restoreDockerComposeLocation = null): ?array
    {
        // Use provided restore values or capture current values as fallback
        $initialDockerComposeLocation = $restoreDockerComposeLocation ?? $this->docker_compose_location;
        $initialBaseDirectory = $restoreBaseDirectory ?? $this->base_directory;
        if ($isInit && $this->docker_compose_raw) {
            return null;
        }
        $uuid = (string) new Cuid2;
        ['commands' => $cloneCommand] = $this->generateGitImportCommands(deployment_uuid: $uuid, only_checkout: true, exec_in_docker: false, custom_base_dir: 'checkout');
        $cloneCommand = str_replace(' clone ', ' clone --quiet ', $cloneCommand);
        $workdir = rtrim($this->base_directory, '/');
        $composeFile = $this->docker_compose_location;
        $fileList = collect([".$workdir$composeFile"]);
        $gitRemoteStatus = $this->getGitRemoteStatus(deployment_uuid: $uuid);
        if (! $gitRemoteStatus['is_accessible']) {
            throw new RuntimeException('Failed to read Git source. Please verify repository access and try again.');
        }
        $server = data_get($this, 'destination.server');
        $getGitVersion = instant_remote_process(['git --version'], $server, false);
        $gitVersion = str($getGitVersion)->explode(' ')->last();

        if (version_compare($gitVersion, '2.35.1', '<')) {
            $fileList = $fileList->map(function ($file) {
                $parts = explode('/', trim($file, '.'));
                $paths = collect();
                $currentPath = '';
                foreach ($parts as $part) {
                    $currentPath .= ($currentPath ? '/' : '').$part;
                    if (str($currentPath)->isNotEmpty()) {
                        $paths->push($currentPath);
                    }
                }

                return $paths;
            })->flatten()->unique()->values();
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'cd checkout',
                'git sparse-checkout init',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        } else {
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
                "mkdir -p /tmp/{$uuid}",
                "cd /tmp/{$uuid}",
                $cloneCommand,
                'cd checkout',
                'git sparse-checkout init --cone',
                "git sparse-checkout set {$fileList->implode(' ')}",
                'git read-tree -mu HEAD',
                "cat .$workdir$composeFile",
            ]);
        }
        try {
            $server = data_get($this, 'destination.server');
            $composeFileContent = instant_remote_process($commands, $server);
        } catch (\Exception $e) {
            // Restore original values on failure only
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            if (str($e->getMessage())->contains('No such file')) {
                throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
            }
            if (str($e->getMessage())->contains('fatal: repository') && str($e->getMessage())->contains('does not exist')) {
                if ($this->deploymentType() === 'deploy_key') {
                    throw new RuntimeException('Your deploy key does not have access to the repository. Please check your deploy key and try again.');
                }
                throw new RuntimeException('Repository does not exist. Please check your repository URL and try again.');
            }
            throw new RuntimeException('Failed to read the Docker Compose file from the repository.');
        } finally {
            // Cleanup only - restoration happens in catch block
            $commands = collect([
                "rm -rf /tmp/{$uuid}",
            ]);
            $server = data_get($this, 'destination.server');
            instant_remote_process($commands, $server, false);
        }
        if ($composeFileContent) {
            $this->docker_compose_raw = $composeFileContent;
            $this->save();
            $parsedServices = $this->parse();
            if ($this->docker_compose_domains) {
                $decoded = json_decode($this->docker_compose_domains, true);
                $json = collect(is_array($decoded) ? $decoded : []);
                $normalized = collect();
                foreach ($json as $key => $value) {
                    $normalizedKey = (string) str($key)->replace('-', '_')->replace('.', '_');
                    $normalized->put($normalizedKey, $value);
                }
                $json = $normalized;
                $servicesData = data_get($parsedServices, 'services', []);
                $services = collect(is_array($servicesData) ? $servicesData : []);
                foreach ($services as $name => $service) {
                    if (str($name)->contains('-') || str($name)->contains('.')) {
                        $replacedName = str($name)->replace('-', '_')->replace('.', '_');
                        $services->put((string) $replacedName, $service);
                        $services->forget((string) $name);
                    }
                }
                $names = $services->keys()->toArray();
                $jsonNames = $json->keys()->toArray();
                $diff = array_diff($jsonNames, $names);
                $json = $json->filter(function ($value, $key) use ($diff) {
                    return ! in_array($key, $diff);
                });
                if ($json) {
                    $this->docker_compose_domains = json_encode($json);
                } else {
                    $this->docker_compose_domains = null;
                }
                $this->save();
            }

            return [
                'parsedServices' => $parsedServices,
                'initialDockerComposeLocation' => $this->docker_compose_location,
            ];
        } else {
            // Restore original values before throwing
            $this->docker_compose_location = $initialDockerComposeLocation;
            $this->base_directory = $initialBaseDirectory;
            $this->save();

            throw new RuntimeException("Docker Compose file not found at: $workdir$composeFile (branch: {$this->git_branch})<br><br>Check if you used the right extension (.yaml or .yml) in the compose file name.");
        }
    }

    public function parseContainerLabels(?ApplicationPreview $preview = null): ?string
    {
        $customLabels = data_get($this, 'custom_labels');
        if (! $customLabels) {
            return null;
        }
        $decoded = base64_decode($customLabels, true);
        if ($decoded === false || base64_encode($decoded) !== $customLabels) {
            $customLabels = str($customLabels)->replace(',', "\n")->toString();
            $this->custom_labels = base64_encode($customLabels);
        }
        $customLabels = base64_decode($this->custom_labels);
        if (mb_detect_encoding($customLabels, 'UTF-8', true) === false) {
            $customLabels = str(implode('|coolify|', generateLabelsApplication($this, $preview)))->replace('|coolify|', "\n")->toString();
        }
        $this->custom_labels = base64_encode($customLabels);
        $this->save();

        return $customLabels;
    }

    /**
     * @return Attribute<array<int, string>, never>
     */
    public function fqdns(): Attribute
    {
        return Attribute::make(
            get: fn () => is_null($this->fqdn)
                ? []
                : explode(',', $this->fqdn),
        );
    }

    public function getFilesFromServer(bool $isInit = false): void
    {
        getFilesystemVolumesFromServer($this, $isInit);
    }

    public function parseHealthcheckFromDockerfile(string $dockerfile, bool $isInit = false): void
    {
        $hasHealthcheck = str($dockerfile)->contains('HEALTHCHECK');
        $dockerfile = str($dockerfile)->trim()->explode("\n");

        // Always check if healthcheck was removed, regardless of health_check_enabled setting
        if (! $hasHealthcheck && $this->custom_healthcheck_found) {
            // HEALTHCHECK was removed from Dockerfile, reset to defaults
            $this->custom_healthcheck_found = false;
            $this->health_check_interval = 5;
            $this->health_check_timeout = 5;
            $this->health_check_retries = 10;
            $this->health_check_start_period = 5;
            $this->save();

            return;
        }

        if ($hasHealthcheck && ($this->isHealthcheckDisabled() || $isInit)) {
            $healthcheckCommand = null;
            $lines = $dockerfile->toArray();
            foreach ($lines as $line) {
                $trimmedLine = trim($line);
                if (str_starts_with($trimmedLine, 'HEALTHCHECK')) {
                    $healthcheckCommand .= trim($trimmedLine, '\\ ');

                    continue;
                }
                if (isset($healthcheckCommand) && str_contains($trimmedLine, '\\')) {
                    $healthcheckCommand .= ' '.trim($trimmedLine, '\\ ');
                }
                if (isset($healthcheckCommand) && ! str_contains($trimmedLine, '\\') && ! empty($healthcheckCommand)) {
                    $healthcheckCommand .= ' '.$trimmedLine;
                    break;
                }
            }
            if (str($healthcheckCommand)->isNotEmpty()) {
                $interval = str($healthcheckCommand)->match('/--interval=([0-9]+[a-zµ]*)/');
                $timeout = str($healthcheckCommand)->match('/--timeout=([0-9]+[a-zµ]*)/');
                $start_period = str($healthcheckCommand)->match('/--start-period=([0-9]+[a-zµ]*)/');
                $retries = str($healthcheckCommand)->match('/--retries=(\d+)/');

                if ($interval->isNotEmpty()) {
                    $this->health_check_interval = parseDockerfileInterval($interval->toString());
                }
                if ($timeout->isNotEmpty()) {
                    $this->health_check_timeout = parseDockerfileInterval($timeout->toString());
                }
                if ($start_period->isNotEmpty()) {
                    $this->health_check_start_period = parseDockerfileInterval($start_period->toString());
                }
                if ($retries->isNotEmpty()) {
                    $this->health_check_retries = $retries->toInteger();
                }
                if ($interval || $timeout || $start_period || $retries) {
                    $this->custom_healthcheck_found = true;
                    $this->save();
                }
            }
        }
    }

    public function getLimits(): array
    {
        return [
            'limits_memory' => $this->limits_memory,
            'limits_memory_swap' => $this->limits_memory_swap,
            'limits_memory_swappiness' => $this->limits_memory_swappiness,
            'limits_memory_reservation' => $this->limits_memory_reservation,
            'limits_cpus' => $this->limits_cpus,
            'limits_cpuset' => $this->limits_cpuset,
            'limits_cpu_shares' => $this->limits_cpu_shares,
        ];
    }

    /**
     * @return string|array<string, mixed>
     */
    public function generateConfig(bool $is_json = false): string|array
    {
        $generator = new ConfigurationGenerator($this);

        if ($is_json) {
            return $generator->toJson();
        }

        return $generator->toArray();
    }

    public function setConfig(string $config): void
    {
        $validator = Validator::make(['config' => $config], [
            'config' => 'required|json',
        ]);
        if ($validator->fails()) {
            throw new \Exception('Invalid JSON format');
        }
        $config = json_decode($config, true);

        $deepValidator = Validator::make(['config' => $config], [
            'config.build_pack' => 'required|string',
            'config.base_directory' => 'required|string',
            'config.publish_directory' => 'required|string',
            'config.ports_exposes' => 'nullable|string',
            'config.settings.is_static' => 'required|boolean',
        ]);
        if ($deepValidator->fails()) {
            throw new \Exception('Invalid data');
        }
        $config = $deepValidator->validated()['config'];

        try {
            $settings = data_get($config, 'settings', []);
            data_forget($config, 'settings');
            $this->update($config);
            $this->settings()->update($settings);
        } catch (\Exception $e) {
            throw new \Exception('Failed to update application settings');
        }
    }
}
