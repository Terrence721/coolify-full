<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $organization
 * @property string $api_url
 * @property string $html_url
 * @property int $custom_port
 * @property string $custom_user
 * @property bool $is_system_wide
 * @property bool $is_public
 * @property int|null $app_id
 * @property string|null $app_secret
 * @property int|null $oauth_id
 * @property string|null $group_name
 * @property string|null $public_key
 * @property string|null $webhook_token
 * @property int|null $deploy_key_id
 * @property int|null $private_key_id
 * @property int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read mixed $image
 * @property-read PrivateKey|null $privateKey
 * @property-read mixed $sanitized_name
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereApiUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereAppId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereAppSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereCustomPort($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereCustomUser($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereDeployKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereGroupName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereHtmlUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereIsPublic($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereIsSystemWide($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereOauthId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereOrganization($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp wherePrivateKeyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp wherePublicKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereTeamId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|GitlabApp whereWebhookToken($value)
 *
 * @mixin \Eloquent
 */
class GitlabApp extends BaseModel
{
    protected $fillable = [
        'name',
        'organization',
        'api_url',
        'html_url',
        'custom_port',
        'custom_user',
        'is_system_wide',
        'is_public',
        'app_id',
        'app_secret',
        'oauth_id',
        'group_name',
        'public_key',
        'webhook_token',
        'deploy_key_id',
    ];

    protected $hidden = [
        'webhook_token',
        'app_secret',
    ];

    public static function ownedByCurrentTeam()
    {
        return GitlabApp::whereTeamId(currentTeam()->id);
    }

    public function applications()
    {
        return $this->morphMany(Application::class, 'source');
    }

    public function privateKey()
    {
        return $this->belongsTo(PrivateKey::class);
    }
}
