<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;

/**
 * @property int $id
 * @property int $team_id
 * @property bool $slack_enabled
 * @property string|null $slack_webhook_url
 * @property bool $deployment_success_slack_notifications
 * @property bool $deployment_failure_slack_notifications
 * @property bool $status_change_slack_notifications
 * @property bool $backup_success_slack_notifications
 * @property bool $backup_failure_slack_notifications
 * @property bool $scheduled_task_success_slack_notifications
 * @property bool $scheduled_task_failure_slack_notifications
 * @property bool $docker_cleanup_success_slack_notifications
 * @property bool $docker_cleanup_failure_slack_notifications
 * @property bool $server_disk_usage_slack_notifications
 * @property bool $server_reachable_slack_notifications
 * @property bool $server_unreachable_slack_notifications
 * @property bool $server_patch_slack_notifications
 * @property bool $traefik_outdated_slack_notifications
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereBackupFailureSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereBackupSuccessSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereDeploymentFailureSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereDeploymentSuccessSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereDockerCleanupFailureSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereDockerCleanupSuccessSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereScheduledTaskFailureSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereScheduledTaskSuccessSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereServerDiskUsageSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereServerPatchSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereServerReachableSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereServerUnreachableSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereSlackEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereSlackWebhookUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereStatusChangeSlackNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|SlackNotificationSettings whereTraefikOutdatedSlackNotifications($value)
 *
 * @mixin \Eloquent
 */
class SlackNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'slack_enabled',
        'slack_webhook_url',

        'deployment_success_slack_notifications',
        'deployment_failure_slack_notifications',
        'status_change_slack_notifications',
        'backup_success_slack_notifications',
        'backup_failure_slack_notifications',
        'scheduled_task_success_slack_notifications',
        'scheduled_task_failure_slack_notifications',
        'docker_cleanup_success_slack_notifications',
        'docker_cleanup_failure_slack_notifications',
        'server_disk_usage_slack_notifications',
        'server_reachable_slack_notifications',
        'server_unreachable_slack_notifications',
        'server_patch_slack_notifications',
        'traefik_outdated_slack_notifications',
    ];

    protected $casts = [
        'slack_enabled' => 'boolean',
        'slack_webhook_url' => 'encrypted',

        'deployment_success_slack_notifications' => 'boolean',
        'deployment_failure_slack_notifications' => 'boolean',
        'status_change_slack_notifications' => 'boolean',
        'backup_success_slack_notifications' => 'boolean',
        'backup_failure_slack_notifications' => 'boolean',
        'scheduled_task_success_slack_notifications' => 'boolean',
        'scheduled_task_failure_slack_notifications' => 'boolean',
        'docker_cleanup_slack_notifications' => 'boolean',
        'server_disk_usage_slack_notifications' => 'boolean',
        'server_reachable_slack_notifications' => 'boolean',
        'server_unreachable_slack_notifications' => 'boolean',
        'server_patch_slack_notifications' => 'boolean',
        'traefik_outdated_slack_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled()
    {
        return $this->slack_enabled;
    }
}
