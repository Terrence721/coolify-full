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
 * @property bool $webhook_enabled
 * @property string|null $webhook_url
 * @property bool $deployment_success_webhook_notifications
 * @property bool $deployment_failure_webhook_notifications
 * @property bool $status_change_webhook_notifications
 * @property bool $backup_success_webhook_notifications
 * @property bool $backup_failure_webhook_notifications
 * @property bool $scheduled_task_success_webhook_notifications
 * @property bool $scheduled_task_failure_webhook_notifications
 * @property bool $docker_cleanup_success_webhook_notifications
 * @property bool $docker_cleanup_failure_webhook_notifications
 * @property bool $server_disk_usage_webhook_notifications
 * @property bool $server_reachable_webhook_notifications
 * @property bool $server_unreachable_webhook_notifications
 * @property bool $server_patch_webhook_notifications
 * @property bool $traefik_outdated_webhook_notifications
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereBackupFailureWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereBackupSuccessWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereDeploymentFailureWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereDeploymentSuccessWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereDockerCleanupFailureWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereDockerCleanupSuccessWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereScheduledTaskFailureWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereScheduledTaskSuccessWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereServerDiskUsageWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereServerPatchWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereServerReachableWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereServerUnreachableWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereStatusChangeWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereTraefikOutdatedWebhookNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereWebhookEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|WebhookNotificationSettings whereWebhookUrl($value)
 *
 * @mixin \Eloquent
 */
class WebhookNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'webhook_enabled',
        'webhook_url',

        'deployment_success_webhook_notifications',
        'deployment_failure_webhook_notifications',
        'status_change_webhook_notifications',
        'backup_success_webhook_notifications',
        'backup_failure_webhook_notifications',
        'scheduled_task_success_webhook_notifications',
        'scheduled_task_failure_webhook_notifications',
        'docker_cleanup_success_webhook_notifications',
        'docker_cleanup_failure_webhook_notifications',
        'server_disk_usage_webhook_notifications',
        'server_reachable_webhook_notifications',
        'server_unreachable_webhook_notifications',
        'server_patch_webhook_notifications',
        'traefik_outdated_webhook_notifications',
    ];

    protected function casts(): array
    {
        return [
            'webhook_enabled' => 'boolean',
            'webhook_url' => 'encrypted',

            'deployment_success_webhook_notifications' => 'boolean',
            'deployment_failure_webhook_notifications' => 'boolean',
            'status_change_webhook_notifications' => 'boolean',
            'backup_success_webhook_notifications' => 'boolean',
            'backup_failure_webhook_notifications' => 'boolean',
            'scheduled_task_success_webhook_notifications' => 'boolean',
            'scheduled_task_failure_webhook_notifications' => 'boolean',
            'docker_cleanup_success_webhook_notifications' => 'boolean',
            'docker_cleanup_failure_webhook_notifications' => 'boolean',
            'server_disk_usage_webhook_notifications' => 'boolean',
            'server_reachable_webhook_notifications' => 'boolean',
            'server_unreachable_webhook_notifications' => 'boolean',
            'server_patch_webhook_notifications' => 'boolean',
            'traefik_outdated_webhook_notifications' => 'boolean',
        ];
    }

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled(): bool
    {
        return $this->webhook_enabled;
    }
}
