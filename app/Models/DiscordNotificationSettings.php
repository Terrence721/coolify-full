<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int $team_id
 * @property bool $discord_enabled
 * @property string|null $discord_webhook_url
 * @property bool $deployment_success_discord_notifications
 * @property bool $deployment_failure_discord_notifications
 * @property bool $status_change_discord_notifications
 * @property bool $backup_success_discord_notifications
 * @property bool $backup_failure_discord_notifications
 * @property bool $scheduled_task_success_discord_notifications
 * @property bool $scheduled_task_failure_discord_notifications
 * @property bool $docker_cleanup_success_discord_notifications
 * @property bool $docker_cleanup_failure_discord_notifications
 * @property bool $server_disk_usage_discord_notifications
 * @property bool $server_reachable_discord_notifications
 * @property bool $server_unreachable_discord_notifications
 * @property bool $discord_ping_enabled
 * @property bool $server_patch_discord_notifications
 * @property bool $traefik_outdated_discord_notifications
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereBackupFailureDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereBackupSuccessDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDeploymentFailureDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDeploymentSuccessDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDiscordEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDiscordPingEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDiscordWebhookUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDockerCleanupFailureDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereDockerCleanupSuccessDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereScheduledTaskFailureDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereScheduledTaskSuccessDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereServerDiskUsageDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereServerPatchDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereServerReachableDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereServerUnreachableDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereStatusChangeDiscordNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|DiscordNotificationSettings whereTraefikOutdatedDiscordNotifications($value)
 *
 * @mixin \Eloquent
 */
class DiscordNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'discord_enabled',
        'discord_webhook_url',

        'deployment_success_discord_notifications',
        'deployment_failure_discord_notifications',
        'status_change_discord_notifications',
        'backup_success_discord_notifications',
        'backup_failure_discord_notifications',
        'scheduled_task_success_discord_notifications',
        'scheduled_task_failure_discord_notifications',
        'docker_cleanup_success_discord_notifications',
        'docker_cleanup_failure_discord_notifications',
        'server_disk_usage_discord_notifications',
        'server_reachable_discord_notifications',
        'server_unreachable_discord_notifications',
        'server_patch_discord_notifications',
        'traefik_outdated_discord_notifications',
        'discord_ping_enabled',
    ];

    protected $casts = [
        'discord_enabled' => 'boolean',
        'discord_webhook_url' => 'encrypted',

        'deployment_success_discord_notifications' => 'boolean',
        'deployment_failure_discord_notifications' => 'boolean',
        'status_change_discord_notifications' => 'boolean',
        'backup_success_discord_notifications' => 'boolean',
        'backup_failure_discord_notifications' => 'boolean',
        'scheduled_task_success_discord_notifications' => 'boolean',
        'scheduled_task_failure_discord_notifications' => 'boolean',
        'docker_cleanup_discord_notifications' => 'boolean',
        'server_disk_usage_discord_notifications' => 'boolean',
        'server_reachable_discord_notifications' => 'boolean',
        'server_unreachable_discord_notifications' => 'boolean',
        'server_patch_discord_notifications' => 'boolean',
        'traefik_outdated_discord_notifications' => 'boolean',
        'discord_ping_enabled' => 'boolean',
    ];

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled(): bool
    {
        return $this->discord_enabled;
    }

    public function isPingEnabled(): bool
    {
        return $this->discord_ping_enabled;
    }
}
