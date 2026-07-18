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
 * @property bool $telegram_enabled
 * @property string|null $telegram_token
 * @property string|null $telegram_chat_id
 * @property bool $deployment_success_telegram_notifications
 * @property bool $deployment_failure_telegram_notifications
 * @property bool $status_change_telegram_notifications
 * @property bool $backup_success_telegram_notifications
 * @property bool $backup_failure_telegram_notifications
 * @property bool $scheduled_task_success_telegram_notifications
 * @property bool $scheduled_task_failure_telegram_notifications
 * @property bool $docker_cleanup_success_telegram_notifications
 * @property bool $docker_cleanup_failure_telegram_notifications
 * @property bool $server_disk_usage_telegram_notifications
 * @property bool $server_reachable_telegram_notifications
 * @property bool $server_unreachable_telegram_notifications
 * @property string|null $telegram_notifications_deployment_success_thread_id
 * @property string|null $telegram_notifications_deployment_failure_thread_id
 * @property string|null $telegram_notifications_status_change_thread_id
 * @property string|null $telegram_notifications_backup_success_thread_id
 * @property string|null $telegram_notifications_backup_failure_thread_id
 * @property string|null $telegram_notifications_scheduled_task_success_thread_id
 * @property string|null $telegram_notifications_scheduled_task_failure_thread_id
 * @property string|null $telegram_notifications_docker_cleanup_success_thread_id
 * @property string|null $telegram_notifications_docker_cleanup_failure_thread_id
 * @property string|null $telegram_notifications_server_disk_usage_thread_id
 * @property string|null $telegram_notifications_server_reachable_thread_id
 * @property string|null $telegram_notifications_server_unreachable_thread_id
 * @property bool $server_patch_telegram_notifications
 * @property string|null $telegram_notifications_server_patch_thread_id
 * @property string|null $telegram_notifications_traefik_outdated_thread_id
 * @property bool $traefik_outdated_telegram_notifications
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereBackupFailureTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereBackupSuccessTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereDeploymentFailureTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereDeploymentSuccessTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereDockerCleanupFailureTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereDockerCleanupSuccessTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereScheduledTaskFailureTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereScheduledTaskSuccessTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereServerDiskUsageTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereServerPatchTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereServerReachableTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereServerUnreachableTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereStatusChangeTelegramNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramChatId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsBackupFailureThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsBackupSuccessThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsDeploymentFailureThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsDeploymentSuccessThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsDockerCleanupFailureThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsDockerCleanupSuccessThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsScheduledTaskFailureThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsScheduledTaskSuccessThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsServerDiskUsageThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsServerPatchThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsServerReachableThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsServerUnreachableThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsStatusChangeThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramNotificationsTraefikOutdatedThreadId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTelegramToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|TelegramNotificationSettings whereTraefikOutdatedTelegramNotifications($value)
 *
 * @mixin \Eloquent
 */
class TelegramNotificationSettings extends Model
{
    use Notifiable;

    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'telegram_enabled',
        'telegram_token',
        'telegram_chat_id',

        'deployment_success_telegram_notifications',
        'deployment_failure_telegram_notifications',
        'status_change_telegram_notifications',
        'backup_success_telegram_notifications',
        'backup_failure_telegram_notifications',
        'scheduled_task_success_telegram_notifications',
        'scheduled_task_failure_telegram_notifications',
        'docker_cleanup_success_telegram_notifications',
        'docker_cleanup_failure_telegram_notifications',
        'server_disk_usage_telegram_notifications',
        'server_reachable_telegram_notifications',
        'server_unreachable_telegram_notifications',
        'server_patch_telegram_notifications',
        'traefik_outdated_telegram_notifications',

        'telegram_notifications_deployment_success_thread_id',
        'telegram_notifications_deployment_failure_thread_id',
        'telegram_notifications_status_change_thread_id',
        'telegram_notifications_backup_success_thread_id',
        'telegram_notifications_backup_failure_thread_id',
        'telegram_notifications_scheduled_task_success_thread_id',
        'telegram_notifications_scheduled_task_failure_thread_id',
        'telegram_notifications_docker_cleanup_success_thread_id',
        'telegram_notifications_docker_cleanup_failure_thread_id',
        'telegram_notifications_server_disk_usage_thread_id',
        'telegram_notifications_server_reachable_thread_id',
        'telegram_notifications_server_unreachable_thread_id',
        'telegram_notifications_server_patch_thread_id',
        'telegram_notifications_traefik_outdated_thread_id',
    ];

    protected $casts = [
        'telegram_enabled' => 'boolean',
        'telegram_token' => 'encrypted',
        'telegram_chat_id' => 'encrypted',

        'deployment_success_telegram_notifications' => 'boolean',
        'deployment_failure_telegram_notifications' => 'boolean',
        'status_change_telegram_notifications' => 'boolean',
        'backup_success_telegram_notifications' => 'boolean',
        'backup_failure_telegram_notifications' => 'boolean',
        'scheduled_task_success_telegram_notifications' => 'boolean',
        'scheduled_task_failure_telegram_notifications' => 'boolean',
        'docker_cleanup_telegram_notifications' => 'boolean',
        'server_disk_usage_telegram_notifications' => 'boolean',
        'server_reachable_telegram_notifications' => 'boolean',
        'server_unreachable_telegram_notifications' => 'boolean',
        'server_patch_telegram_notifications' => 'boolean',
        'traefik_outdated_telegram_notifications' => 'boolean',

        'telegram_notifications_deployment_success_thread_id' => 'encrypted',
        'telegram_notifications_deployment_failure_thread_id' => 'encrypted',
        'telegram_notifications_status_change_thread_id' => 'encrypted',
        'telegram_notifications_backup_success_thread_id' => 'encrypted',
        'telegram_notifications_backup_failure_thread_id' => 'encrypted',
        'telegram_notifications_scheduled_task_success_thread_id' => 'encrypted',
        'telegram_notifications_scheduled_task_failure_thread_id' => 'encrypted',
        'telegram_notifications_docker_cleanup_thread_id' => 'encrypted',
        'telegram_notifications_server_disk_usage_thread_id' => 'encrypted',
        'telegram_notifications_server_reachable_thread_id' => 'encrypted',
        'telegram_notifications_server_unreachable_thread_id' => 'encrypted',
        'telegram_notifications_server_patch_thread_id' => 'encrypted',
        'telegram_notifications_traefik_outdated_thread_id' => 'encrypted',
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
        return $this->telegram_enabled;
    }
}
