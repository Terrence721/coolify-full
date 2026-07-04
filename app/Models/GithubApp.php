<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * @property-read Collection<int, Application> $applications
 * @property-read Team|null $team
 * @property int $id
 * @property string $uuid
 * @property string $name
 * @property string|null $organization
 * @property string $api_url
 * @property string $html_url
 * @property string $custom_user
 * @property int $custom_port
 * @property int|null $app_id
 * @property int|null $installation_id
 * @property string|null $client_id
 * @property string|null $client_secret
 * @property string|null $webhook_secret
 * @property bool $is_system_wide
 * @property bool $is_public
 * @property int|null $private_key_id
 * @property int $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $contents
 * @property string|null $metadata
 * @property string|null $pull_requests
 * @property string|null $administration
 * @property-read int|null $applications_count
 * @property-read mixed $image
 * @property-read PrivateKey|null $privateKey
 * @property-read mixed $sanitized_name
 * @property-read mixed $type
 *
 * @method static Builder<static>|GithubApp newModelQuery()
 * @method static Builder<static>|GithubApp newQuery()
 * @method static Builder<static>|GithubApp query()
 * @method static Builder<static>|GithubApp whereAdministration($value)
 * @method static Builder<static>|GithubApp whereApiUrl($value)
 * @method static Builder<static>|GithubApp whereAppId($value)
 * @method static Builder<static>|GithubApp whereClientId($value)
 * @method static Builder<static>|GithubApp whereClientSecret($value)
 * @method static Builder<static>|GithubApp whereContents($value)
 * @method static Builder<static>|GithubApp whereCreatedAt($value)
 * @method static Builder<static>|GithubApp whereCustomPort($value)
 * @method static Builder<static>|GithubApp whereCustomUser($value)
 * @method static Builder<static>|GithubApp whereHtmlUrl($value)
 * @method static Builder<static>|GithubApp whereId($value)
 * @method static Builder<static>|GithubApp whereInstallationId($value)
 * @method static Builder<static>|GithubApp whereIsPublic($value)
 * @method static Builder<static>|GithubApp whereIsSystemWide($value)
 * @method static Builder<static>|GithubApp whereMetadata($value)
 * @method static Builder<static>|GithubApp whereName($value)
 * @method static Builder<static>|GithubApp whereOrganization($value)
 * @method static Builder<static>|GithubApp wherePrivateKeyId($value)
 * @method static Builder<static>|GithubApp wherePullRequests($value)
 * @method static Builder<static>|GithubApp whereTeamId($value)
 * @method static Builder<static>|GithubApp whereUpdatedAt($value)
 * @method static Builder<static>|GithubApp whereUuid($value)
 * @method static Builder<static>|GithubApp whereWebhookSecret($value)
 *
 * @mixin \Eloquent
 */
class GithubApp extends BaseModel
{
    protected $fillable = [
        'team_id',
        'private_key_id',
        'name',
        'organization',
        'api_url',
        'html_url',
        'custom_user',
        'custom_port',
        'app_id',
        'installation_id',
        'client_id',
        'client_secret',
        'webhook_secret',
        'is_system_wide',
        'is_public',
        'contents',
        'metadata',
        'pull_requests',
        'administration',
    ];

    protected $appends = ['type'];

    protected $casts = [
        'is_public' => 'boolean',
        'is_system_wide' => 'boolean',
        'type' => 'string',
    ];

    protected $hidden = [
        'client_secret',
        'webhook_secret',
    ];

    protected static function booted(): void
    {
        static::deleting(function (GithubApp $github_app) {
            $applications_count = Application::where('source_id', $github_app->id)->count();
            if ($applications_count > 0) {
                throw new \Exception('You cannot delete this GitHub App because it is in use by '.$applications_count.' application(s). Delete them first.');
            }

            $privateKey = $github_app->privateKey;
            if ($privateKey) {
                // Check if key is used by anything EXCEPT this GitHub app
                $isUsedElsewhere = $privateKey->servers()->exists()
                    || $privateKey->applications()->exists()
                    || $privateKey->githubApps()->where('id', '!=', $github_app->id)->exists()
                    || $privateKey->gitlabApps()->exists();

                if (! $isUsedElsewhere) {
                    $privateKey->delete();
                } else {
                }
            }
        });
    }

    /** @return Builder<GithubApp> */
    public static function ownedByCurrentTeam(): Builder
    {
        $team = currentTeam();

        return GithubApp::where(function (Builder $query) use ($team) {
            if ($team) {
                $query->where('team_id', $team->id)
                    ->orWhere('is_system_wide', true);

                return;
            }

            $query->where('is_system_wide', true);
        });
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * @return MorphMany<Application, $this>
     */
    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'source');
    }

    /**
     * @return BelongsTo<PrivateKey, $this>
     */
    public function privateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class);
    }

    public function type(): Attribute
    {
        return Attribute::make(
            get: function () {
                if ($this->getMorphClass() === GithubApp::class) {
                    return 'github';
                }
            },
        );
    }
}
