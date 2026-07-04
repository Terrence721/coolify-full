<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Once;
use Spatie\Url\Url;

/**
 * @property int $id
 * @property string|null $public_ipv4
 * @property string|null $public_ipv6
 * @property string|null $fqdn
 * @property int $public_port_min
 * @property int $public_port_max
 * @property bool $do_not_track
 * @property bool $is_auto_update_enabled
 * @property bool $is_registration_enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $next_channel
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
 * @property bool $is_dns_validation_enabled
 * @property string|null $custom_dns_servers
 * @property string|null $instance_name
 * @property bool $is_api_enabled
 * @property string|null $allowed_ips
 * @property string $auto_update_frequency
 * @property string $update_check_frequency
 * @property bool $new_version_available
 * @property string $instance_timezone
 * @property string $helper_version
 * @property bool $disable_two_step_confirmation
 * @property bool $is_sponsorship_popup_enabled
 * @property string|null $dev_helper_version
 * @property bool $is_wire_navigate_enabled
 * @property bool $is_mcp_server_enabled
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereAllowedIps($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereAutoUpdateFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereCustomDnsServers($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereDevHelperVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereDisableTwoStepConfirmation($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereDoNotTrack($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereFqdn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereHelperVersion($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereInstanceName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereInstanceTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsApiEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsAutoUpdateEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsDnsValidationEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsMcpServerEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsRegistrationEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsSponsorshipPopupEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereIsWireNavigateEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereNewVersionAvailable($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereNextChannel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings wherePublicIpv4($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings wherePublicIpv6($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings wherePublicPortMax($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings wherePublicPortMin($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereResendApiKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereResendEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpEncryption($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpFromAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpFromName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpHost($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpPassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpRecipients($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpTimeout($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereSmtpUsername($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereUpdateCheckFrequency($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|InstanceSettings whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class InstanceSettings extends Model
{
    protected $fillable = [
        'public_ipv4',
        'public_ipv6',
        'fqdn',
        'public_port_min',
        'public_port_max',
        'do_not_track',
        'is_auto_update_enabled',
        'is_registration_enabled',
        'next_channel',
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
        'is_dns_validation_enabled',
        'custom_dns_servers',
        'instance_name',
        'is_api_enabled',
        'allowed_ips',
        'auto_update_frequency',
        'update_check_frequency',
        'new_version_available',
        'instance_timezone',
        'helper_version',
        'disable_two_step_confirmation',
        'is_sponsorship_popup_enabled',
        'dev_helper_version',
        'is_wire_navigate_enabled',
        'is_mcp_server_enabled',
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

        'allowed_ip_ranges' => 'array',
        'is_auto_update_enabled' => 'boolean',
        'auto_update_frequency' => 'string',
        'update_check_frequency' => 'string',
        'sentinel_token' => 'encrypted',
        'is_wire_navigate_enabled' => 'boolean',
        'is_mcp_server_enabled' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::updated(function ($settings) {
            // Clear once() cache so subsequent calls get fresh data
            Once::flush();

            // Clear trusted hosts cache when FQDN changes
            if ($settings->wasChanged('fqdn')) {
                Cache::forget('instance_settings_fqdn_host');
            }
        });
    }

    public function fqdn(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if ($value) {
                    $url = Url::fromString($value);
                    $host = $url->getHost();

                    return $url->getScheme().'://'.$host;
                }
            }
        );
    }

    public function updateCheckFrequency(): Attribute
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

    public function autoUpdateFrequency(): Attribute
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

    public static function get()
    {
        return once(fn () => InstanceSettings::findOrFail(0));
    }

    // public function getRecipients($notification)
    // {
    //     $recipients = data_get($notification, 'emails', null);
    //     if (is_null($recipients) || $recipients === '') {
    //         return [];
    //     }

    //     return explode(',', $recipients);
    // }

    public function getTitleDisplayName(): string
    {
        $instanceName = $this->instance_name;
        if (! $instanceName) {
            return '';
        }

        return "[{$instanceName}]";
    }

    // public function helperVersion(): Attribute
    // {
    //     return Attribute::make(
    //         get: function ($value) {
    //             if (isDev()) {
    //                 return 'latest';
    //             }

    //             return $value;
    //         }
    //     );
    // }
}
