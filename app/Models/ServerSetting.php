<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use OpenApi\Attributes as OA;

/**
 * @property int $id
 * @property bool $is_swarm_manager
 * @property bool $is_jump_server
 * @property bool $is_build_server
 * @property bool $is_reachable
 * @property bool $is_usable
 * @property int $server_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $wildcard_domain
 * @property bool $is_cloudflare_tunnel
 * @property bool $is_logdrain_newrelic_enabled
 * @property string|null $logdrain_newrelic_license_key
 * @property string|null $logdrain_newrelic_base_uri
 * @property bool $is_logdrain_highlight_enabled
 * @property string|null $logdrain_highlight_project_id
 * @property bool $is_logdrain_axiom_enabled
 * @property string|null $logdrain_axiom_dataset_name
 * @property string|null $logdrain_axiom_api_key
 * @property bool $is_swarm_worker
 * @property bool $is_logdrain_custom_enabled
 * @property string|null $logdrain_custom_config
 * @property string|null $logdrain_custom_config_parser
 * @property int $concurrent_builds
 * @property int $dynamic_timeout
 * @property bool $force_disabled
 * @property bool $is_metrics_enabled
 * @property bool $generate_exact_labels
 * @property bool $force_docker_cleanup
 * @property string $docker_cleanup_frequency
 * @property int $docker_cleanup_threshold
 * @property string $server_timezone
 * @property bool $delete_unused_volumes
 * @property bool $delete_unused_networks
 * @property bool $is_sentinel_enabled
 * @property string|null $sentinel_token
 * @property int $sentinel_metrics_refresh_rate_seconds
 * @property int $sentinel_metrics_history_days
 * @property int $sentinel_push_interval_seconds
 * @property string|null $sentinel_custom_url
 * @property int $server_disk_usage_notification_threshold
 * @property bool $is_sentinel_debug_enabled
 * @property string $server_disk_usage_check_frequency
 * @property bool $is_terminal_enabled
 * @property int $deployment_queue_limit
 * @property bool $disable_application_image_retention
 * @property int $connection_timeout
 * @property-read Server|null $server
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereConcurrentBuilds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereConnectionTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDeleteUnusedNetworks($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDeleteUnusedVolumes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDeploymentQueueLimit($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDisableApplicationImageRetention($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDockerCleanupFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDockerCleanupThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereDynamicTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereForceDisabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereForceDockerCleanup($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereGenerateExactLabels($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsBuildServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsCloudflareTunnel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsJumpServer($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsLogdrainAxiomEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsLogdrainCustomEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsLogdrainHighlightEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsLogdrainNewrelicEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsMetricsEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsReachable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsSentinelDebugEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsSentinelEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsSwarmManager($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsSwarmWorker($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsTerminalEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereIsUsable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainAxiomApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainAxiomDatasetName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainCustomConfig($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainCustomConfigParser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainHighlightProjectId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainNewrelicBaseUri($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereLogdrainNewrelicLicenseKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereSentinelCustomUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereSentinelMetricsHistoryDays($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereSentinelMetricsRefreshRateSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereSentinelPushIntervalSeconds($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereSentinelToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereServerDiskUsageCheckFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereServerDiskUsageNotificationThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereServerId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereServerTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ServerSetting whereWildcardDomain($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Server Settings model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer'),
        new OA\Property(property: 'concurrent_builds', type: 'integer'),
        new OA\Property(property: 'deployment_queue_limit', type: 'integer'),
        new OA\Property(property: 'dynamic_timeout', type: 'integer'),
        new OA\Property(property: 'force_disabled', type: 'boolean'),
        new OA\Property(property: 'force_server_cleanup', type: 'boolean'),
        new OA\Property(property: 'is_build_server', type: 'boolean'),
        new OA\Property(property: 'is_cloudflare_tunnel', type: 'boolean'),
        new OA\Property(property: 'is_jump_server', type: 'boolean'),
        new OA\Property(property: 'is_logdrain_axiom_enabled', type: 'boolean'),
        new OA\Property(property: 'is_logdrain_custom_enabled', type: 'boolean'),
        new OA\Property(property: 'is_logdrain_highlight_enabled', type: 'boolean'),
        new OA\Property(property: 'is_logdrain_newrelic_enabled', type: 'boolean'),
        new OA\Property(property: 'is_metrics_enabled', type: 'boolean'),
        new OA\Property(property: 'is_reachable', type: 'boolean'),
        new OA\Property(property: 'is_sentinel_enabled', type: 'boolean'),
        new OA\Property(property: 'is_swarm_manager', type: 'boolean'),
        new OA\Property(property: 'is_swarm_worker', type: 'boolean'),
        new OA\Property(property: 'is_terminal_enabled', type: 'boolean'),
        new OA\Property(property: 'is_usable', type: 'boolean'),
        new OA\Property(property: 'logdrain_axiom_api_key', type: 'string'),
        new OA\Property(property: 'logdrain_axiom_dataset_name', type: 'string'),
        new OA\Property(property: 'logdrain_custom_config', type: 'string'),
        new OA\Property(property: 'logdrain_custom_config_parser', type: 'string'),
        new OA\Property(property: 'logdrain_highlight_project_id', type: 'string'),
        new OA\Property(property: 'logdrain_newrelic_base_uri', type: 'string'),
        new OA\Property(property: 'logdrain_newrelic_license_key', type: 'string'),
        new OA\Property(property: 'sentinel_metrics_history_days', type: 'integer'),
        new OA\Property(property: 'sentinel_metrics_refresh_rate_seconds', type: 'integer'),
        new OA\Property(property: 'sentinel_token', type: 'string'),
        new OA\Property(property: 'docker_cleanup_frequency', type: 'string'),
        new OA\Property(property: 'docker_cleanup_threshold', type: 'integer'),
        new OA\Property(property: 'server_id', type: 'integer'),
        new OA\Property(property: 'wildcard_domain', type: 'string'),
        new OA\Property(property: 'created_at', type: 'string'),
        new OA\Property(property: 'updated_at', type: 'string'),
        new OA\Property(property: 'delete_unused_volumes', type: 'boolean', description: 'The flag to indicate if the unused volumes should be deleted.'),
        new OA\Property(property: 'delete_unused_networks', type: 'boolean', description: 'The flag to indicate if the unused networks should be deleted.'),
        new OA\Property(property: 'connection_timeout', type: 'integer', description: 'SSH connection timeout in seconds.'),
    ]
)]
class ServerSetting extends Model
{
    protected $fillable = [
        'server_id',
        'is_swarm_manager',
        'is_jump_server',
        'is_build_server',
        'is_reachable',
        'is_usable',
        'wildcard_domain',
        'is_cloudflare_tunnel',
        'is_logdrain_newrelic_enabled',
        'logdrain_newrelic_license_key',
        'logdrain_newrelic_base_uri',
        'is_logdrain_highlight_enabled',
        'logdrain_highlight_project_id',
        'is_logdrain_axiom_enabled',
        'logdrain_axiom_dataset_name',
        'logdrain_axiom_api_key',
        'is_swarm_worker',
        'is_logdrain_custom_enabled',
        'logdrain_custom_config',
        'logdrain_custom_config_parser',
        'concurrent_builds',
        'dynamic_timeout',
        'force_disabled',
        'is_metrics_enabled',
        'generate_exact_labels',
        'force_docker_cleanup',
        'docker_cleanup_frequency',
        'docker_cleanup_threshold',
        'server_timezone',
        'delete_unused_volumes',
        'delete_unused_networks',
        'is_sentinel_enabled',
        'sentinel_token',
        'sentinel_metrics_refresh_rate_seconds',
        'sentinel_metrics_history_days',
        'sentinel_push_interval_seconds',
        'sentinel_custom_url',
        'server_disk_usage_notification_threshold',
        'is_sentinel_debug_enabled',
        'server_disk_usage_check_frequency',
        'is_terminal_enabled',
        'deployment_queue_limit',
        'disable_application_image_retention',
        'connection_timeout',
    ];

    protected $casts = [
        'force_disabled' => 'boolean',
        'force_docker_cleanup' => 'boolean',
        'docker_cleanup_threshold' => 'integer',
        'sentinel_token' => 'encrypted',
        'is_reachable' => 'boolean',
        'is_usable' => 'boolean',
        'is_terminal_enabled' => 'boolean',
        'disable_application_image_retention' => 'boolean',
        'connection_timeout' => 'integer',
    ];

    protected static function booted()
    {
        static::creating(function ($setting) {
            try {
                if (str($setting->sentinel_token)->isEmpty()) {
                    $setting->generateSentinelToken(save: false, ignoreEvent: true);
                }
                if (str($setting->sentinel_custom_url)->isEmpty()) {
                    $setting->generateSentinelUrl(save: false, ignoreEvent: true);
                }
            } catch (\Throwable $e) {
                Log::error('Error creating server setting: '.$e->getMessage());
            }
        });
        static::updated(function ($settings) {
            if (
                $settings->wasChanged('sentinel_token') ||
                $settings->wasChanged('sentinel_custom_url') ||
                $settings->wasChanged('sentinel_metrics_refresh_rate_seconds') ||
                $settings->wasChanged('sentinel_metrics_history_days') ||
                $settings->wasChanged('sentinel_push_interval_seconds')
            ) {
                $settings->server->restartSentinel();
            }
        });
    }

    /**
     * Validate that a sentinel token contains only safe characters.
     * Prevents OS command injection when the token is interpolated into shell commands.
     */
    public static function isValidSentinelToken(?string $token): bool
    {
        if ($token === null) {
            return false;
        }

        return (bool) preg_match('/\A[a-zA-Z0-9._\-+=\/]+\z/', $token);
    }

    /**
     * Returns a valid sentinel token, regenerating it if the stored value is
     * empty, undecryptable, or otherwise invalid. Throws only when regeneration
     * still fails to produce a valid token.
     */
    public function ensureValidSentinelToken(): string
    {
        try {
            $token = $this->sentinel_token;
        } catch (DecryptException) {
            $token = null;
        }

        if (! self::isValidSentinelToken($token)) {
            // Clear undecryptable raw value so Eloquent's dirty-check won't try to
            // decrypt the bad original during save().
            $attrs = $this->getAttributes();
            $attrs['sentinel_token'] = null;
            $this->setRawAttributes($attrs, true);

            $this->generateSentinelToken(save: true, ignoreEvent: true);
            $this->refresh();
            $token = $this->sentinel_token;
        }

        if (! self::isValidSentinelToken($token)) {
            throw new \RuntimeException('Sentinel token invalid after regeneration. Allowed characters: a-z, A-Z, 0-9, dot, underscore, hyphen, plus, slash, equals.');
        }

        return $token;
    }

    public function generateSentinelToken(bool $save = true, bool $ignoreEvent = false): string
    {
        $data = [
            'server_uuid' => $this->server->uuid,
        ];
        $token = encrypt(json_encode($data));
        $this->sentinel_token = $token;
        if ($save) {
            if ($ignoreEvent) {
                $this->saveQuietly();
            } else {
                $this->save();
            }
        }

        return $token;
    }

    public function generateSentinelUrl(bool $save = true, bool $ignoreEvent = false)
    {
        $domain = null;
        $settings = InstanceSettings::get();
        if ($this->server->isLocalhost()) {
            $domain = 'http://host.docker.internal:8000';
        } elseif ($settings->fqdn) {
            $domain = $settings->fqdn;
        } elseif ($settings->public_ipv4) {
            $domain = 'http://'.$settings->public_ipv4.':8000';
        } elseif ($settings->public_ipv6) {
            $domain = 'http://'.$settings->public_ipv6.':8000';
        }
        $this->sentinel_custom_url = $domain;
        if ($save) {
            if ($ignoreEvent) {
                $this->saveQuietly();
            } else {
                $this->save();
            }
        }

        return $domain;
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function dockerCleanupFrequency(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                return translate_cron_expression($value);
            },
            get: function ($value) {
                return translate_cron_expression($value);
            }
        );
    }
}
