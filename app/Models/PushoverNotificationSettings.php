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
 * @property bool $pushover_enabled
 * @property string|null $pushover_user_key
 * @property string|null $pushover_api_token
 * @property bool $deployment_success_pushover_notifications
 * @property bool $deployment_failure_pushover_notifications
 * @property bool $status_change_pushover_notifications
 * @property bool $backup_success_pushover_notifications
 * @property bool $backup_failure_pushover_notifications
 * @property bool $scheduled_task_success_pushover_notifications
 * @property bool $scheduled_task_failure_pushover_notifications
 * @property bool $docker_cleanup_success_pushover_notifications
 * @property bool $docker_cleanup_failure_pushover_notifications
 * @property bool $server_disk_usage_pushover_notifications
 * @property bool $server_reachable_pushover_notifications
 * @property bool $server_unreachable_pushover_notifications
 * @property bool $server_patch_pushover_notifications
 * @property bool $traefik_outdated_pushover_notifications
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereBackupFailurePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereBackupSuccessPushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereDeploymentFailurePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereDeploymentSuccessPushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereDockerCleanupFailurePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereDockerCleanupSuccessPushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings wherePushoverApiToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings wherePushoverEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings wherePushoverUserKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereScheduledTaskFailurePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereScheduledTaskSuccessPushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereServerDiskUsagePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereServerPatchPushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereServerReachablePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereServerUnreachablePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereStatusChangePushoverNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|PushoverNotificationSettings whereTraefikOutdatedPushoverNotifications($value)
 *
 * @mixin \Eloquent
 */
class PushoverNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'pushover_enabled',
        'pushover_user_key',
        'pushover_api_token',

        'deployment_success_pushover_notifications',
        'deployment_failure_pushover_notifications',
        'status_change_pushover_notifications',
        'backup_success_pushover_notifications',
        'backup_failure_pushover_notifications',
        'scheduled_task_success_pushover_notifications',
        'scheduled_task_failure_pushover_notifications',
        'docker_cleanup_success_pushover_notifications',
        'docker_cleanup_failure_pushover_notifications',
        'server_disk_usage_pushover_notifications',
        'server_reachable_pushover_notifications',
        'server_unreachable_pushover_notifications',
        'server_patch_pushover_notifications',
        'traefik_outdated_pushover_notifications',
    ];

    protected $casts = [
        'pushover_enabled' => 'boolean',
        'pushover_user_key' => 'encrypted',
        'pushover_api_token' => 'encrypted',

        'deployment_success_pushover_notifications' => 'boolean',
        'deployment_failure_pushover_notifications' => 'boolean',
        'status_change_pushover_notifications' => 'boolean',
        'backup_success_pushover_notifications' => 'boolean',
        'backup_failure_pushover_notifications' => 'boolean',
        'scheduled_task_success_pushover_notifications' => 'boolean',
        'scheduled_task_failure_pushover_notifications' => 'boolean',
        'docker_cleanup_pushover_notifications' => 'boolean',
        'server_disk_usage_pushover_notifications' => 'boolean',
        'server_reachable_pushover_notifications' => 'boolean',
        'server_unreachable_pushover_notifications' => 'boolean',
        'server_patch_pushover_notifications' => 'boolean',
        'traefik_outdated_pushover_notifications' => 'boolean',
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
        return $this->pushover_enabled;
    }
}
