<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $team_id
 * @property bool $smtp_enabled
 * @property string|null $smtp_from_address
 * @property string|null $smtp_from_name
 * @property string|null $smtp_recipients
 * @property string|null $smtp_host
 * @property int|null $smtp_port
 * @property string|null $smtp_encryption
 * @property string|null $smtp_username
 * @property string|null $smtp_password
 * @property int|null $smtp_timeout
 * @property bool $resend_enabled
 * @property string|null $resend_api_key
 * @property bool $use_instance_email_settings
 * @property bool $deployment_success_email_notifications
 * @property bool $deployment_failure_email_notifications
 * @property bool $status_change_email_notifications
 * @property bool $backup_success_email_notifications
 * @property bool $backup_failure_email_notifications
 * @property bool $scheduled_task_success_email_notifications
 * @property bool $scheduled_task_failure_email_notifications
 * @property bool $docker_cleanup_success_email_notifications
 * @property bool $docker_cleanup_failure_email_notifications
 * @property bool $server_disk_usage_email_notifications
 * @property bool $server_reachable_email_notifications
 * @property bool $server_unreachable_email_notifications
 * @property bool $server_patch_email_notifications
 * @property bool $traefik_outdated_email_notifications
 * @property-read Team $team
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereBackupFailureEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereBackupSuccessEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereDeploymentFailureEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereDeploymentSuccessEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereDockerCleanupFailureEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereDockerCleanupSuccessEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereResendApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereResendEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereScheduledTaskFailureEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereScheduledTaskSuccessEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereServerDiskUsageEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereServerPatchEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereServerReachableEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereServerUnreachableEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpEncryption($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpFromName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereSmtpUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereStatusChangeEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereTraefikOutdatedEmailNotifications($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|EmailNotificationSettings whereUseInstanceEmailSettings($value)
 *
 * @mixin \Eloquent
 */
class EmailNotificationSettings extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'team_id',

        'smtp_enabled',
        'smtp_from_address',
        'smtp_from_name',
        'smtp_recipients',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_timeout',

        'resend_enabled',
        'resend_api_key',

        'use_instance_email_settings',

        'deployment_success_email_notifications',
        'deployment_failure_email_notifications',
        'status_change_email_notifications',
        'backup_success_email_notifications',
        'backup_failure_email_notifications',
        'scheduled_task_success_email_notifications',
        'scheduled_task_failure_email_notifications',
        'docker_cleanup_success_email_notifications',
        'docker_cleanup_failure_email_notifications',
        'server_disk_usage_email_notifications',
        'server_reachable_email_notifications',
        'server_unreachable_email_notifications',
        'server_patch_email_notifications',
        'traefik_outdated_email_notifications',
    ];

    protected $casts = [
        'smtp_enabled' => 'boolean',
        'smtp_from_address' => 'encrypted',
        'smtp_from_name' => 'encrypted',
        'smtp_recipients' => 'encrypted',
        'smtp_host' => 'encrypted',
        'smtp_port' => 'integer',
        'smtp_username' => 'encrypted',
        'smtp_password' => 'encrypted',
        'smtp_timeout' => 'integer',

        'resend_enabled' => 'boolean',
        'resend_api_key' => 'encrypted',

        'use_instance_email_settings' => 'boolean',

        'deployment_success_email_notifications' => 'boolean',
        'deployment_failure_email_notifications' => 'boolean',
        'status_change_email_notifications' => 'boolean',
        'backup_success_email_notifications' => 'boolean',
        'backup_failure_email_notifications' => 'boolean',
        'scheduled_task_success_email_notifications' => 'boolean',
        'scheduled_task_failure_email_notifications' => 'boolean',
        'server_disk_usage_email_notifications' => 'boolean',
        'server_patch_email_notifications' => 'boolean',
        'traefik_outdated_email_notifications' => 'boolean',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function isEnabled(): bool
    {
        return $this->smtp_enabled || $this->resend_enabled || $this->use_instance_email_settings;
    }
}
