<?php

declare(strict_types=1);

namespace App\Models;

use App\Actions\User\RevokeUserTeamTokens;
use App\Notifications\Channels\SendsDiscord;
use App\Notifications\Channels\SendsEmail;
use App\Notifications\Channels\SendsPushover;
use App\Notifications\Channels\SendsSlack;
use App\Traits\HasNotificationSettings;
use App\Traits\HasSafeStringAttribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Auth;
use OpenApi\Attributes as OA;

/**
 * @property-read Collection<int, User> $members
 * @property-read Collection<int, Server> $servers
 * @property-read TeamUserPivot|null $pivot
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property bool $personal_team
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property bool $show_boarding
 * @property-read Collection<int, Application> $applications
 * @property-read int|null $applications_count
 * @property-read Collection<int, CloudProviderToken> $cloudProviderTokens
 * @property-read int|null $cloud_provider_tokens_count
 * @property-read DiscordNotificationSettings|null $discordNotificationSettings
 * @property-read EmailNotificationSettings|null $emailNotificationSettings
 * @property-read Collection<int, SharedEnvironmentVariable> $environment_variables
 * @property-read int|null $environment_variables_count
 * @property-read Collection<int, TeamInvitation> $invitations
 * @property-read int|null $invitations_count
 * @property-read int|null $members_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, PrivateKey> $privateKeys
 * @property-read int|null $private_keys_count
 * @property-read Collection<int, Project> $projects
 * @property-read int|null $projects_count
 * @property-read PushoverNotificationSettings|null $pushoverNotificationSettings
 * @property-read Collection<int, S3Storage> $s3s
 * @property-read int|null $s3s_count
 * @property-read int|null $servers_count
 * @property-read SlackNotificationSettings|null $slackNotificationSettings
 * @property-read TelegramNotificationSettings|null $telegramNotificationSettings
 * @property-read WebhookNotificationSettings|null $webhookNotificationSettings
 *
 * @method static \Database\Factories\TeamFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereDescription($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team wherePersonalTeam($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereShowBoarding($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Team whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
#[OA\Schema(
    description: 'Team model',
    type: 'object',
    properties: [
        new OA\Property(property: 'id', type: 'integer', description: 'The unique identifier of the team.'),
        new OA\Property(property: 'name', type: 'string', description: 'The name of the team.'),
        new OA\Property(property: 'description', type: 'string', description: 'The description of the team.'),
        new OA\Property(property: 'personal_team', type: 'boolean', description: 'Whether the team is personal or not.'),
        new OA\Property(property: 'created_at', type: 'string', description: 'The date and time the team was created.'),
        new OA\Property(property: 'updated_at', type: 'string', description: 'The date and time the team was last updated.'),
        new OA\Property(property: 'show_boarding', type: 'boolean', description: 'Whether to show the boarding screen or not.'),
        'members' => new OA\Property(
            property: 'members',
            type: 'array',
            items: new OA\Items(ref: '#/components/schemas/User'),
            description: 'The members of the team.'
        ),
    ]
)]
class Team extends Model implements SendsDiscord, SendsEmail, SendsPushover, SendsSlack
{
    use HasFactory, HasNotificationSettings, HasSafeStringAttribute, Notifiable;

    protected $fillable = [
        'name',
        'description',
        'personal_team',
        'show_boarding',
    ];

    protected $casts = [
        'personal_team' => 'boolean',
        'show_boarding' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($team) {
            $team->emailNotificationSettings()->create([
                'use_instance_email_settings' => isDev(),
            ]);
            $team->discordNotificationSettings()->create();
            $team->slackNotificationSettings()->create();
            $team->telegramNotificationSettings()->create();
            $team->pushoverNotificationSettings()->create();
            $team->webhookNotificationSettings()->create();
        });

        static::saving(function ($team) {
            $user = Auth::user();
            if ($user instanceof User && $user->isMember()) {
                throw new \Exception('You are not allowed to update this team.');
            }
        });

        static::deleting(function (Team $team) {
            RevokeUserTeamTokens::forTeam($team->id);

            foreach ($team->privateKeys as $key) {
                $key->delete();
            }

            // Transfer instance-wide sources to root team so they remain available
            GithubApp::where('team_id', $team->id)->where('is_system_wide', true)->update(['team_id' => 0]);
            GitlabApp::where('team_id', $team->id)->where('is_system_wide', true)->update(['team_id' => 0]);

            // Delete non-instance-wide sources owned by this team
            /** @var SupportCollection<int, GithubApp|GitlabApp> $teamSources */
            $teamSources = collect();
            $teamSources = $teamSources->merge(GithubApp::where('team_id', $team->id)->get())
                ->merge(GitlabApp::where('team_id', $team->id)->get());
            foreach ($teamSources as $source) {
                $source->delete();
            }

            foreach (Tag::whereTeamId($team->id)->get() as $tag) {
                $tag->delete();
            }

            foreach ($team->environment_variables()->get() as $sharedVariable) {
                $sharedVariable->delete();
            }

            foreach ($team->s3s as $s3) {
                $s3->delete();
            }
        });
    }

    public function routeNotificationForDiscord(): ?string
    {
        return data_get($this, 'discord_webhook_url', null);
    }

    /**
     * @return array<string, string|null>
     */
    public function routeNotificationForTelegram(): array
    {
        return [
            'token' => data_get($this, 'telegram_token', null),
            'chat_id' => data_get($this, 'telegram_chat_id', null),
        ];
    }

    public function routeNotificationForSlack(): ?string
    {
        return data_get($this, 'slack_webhook_url', null);
    }

    public function routeNotificationForPushover(): array
    {
        return [
            'user' => data_get($this, 'pushover_user_key', null),
            'token' => data_get($this, 'pushover_api_token', null),
        ];
    }

    public function getRecipients(): array
    {
        $recipients = $this->members()->pluck('email')->toArray();

        return array_values(array_filter($recipients, function ($email) {
            return filter_var($email, FILTER_VALIDATE_EMAIL);
        }));
    }

    public function isAnyNotificationEnabled(): bool
    {
        if (isCloud()) {
            return true;
        }

        return $this->getNotificationSettings('email')?->isEnabled() ||
            $this->getNotificationSettings('discord')?->isEnabled() ||
            $this->getNotificationSettings('slack')?->isEnabled() ||
            $this->getNotificationSettings('telegram')?->isEnabled() ||
            $this->getNotificationSettings('pushover')?->isEnabled() ||
            $this->getNotificationSettings('webhook')?->isEnabled();
    }

    /**
     * @return HasMany<SharedEnvironmentVariable, $this>
     */
    public function environment_variables(): HasMany
    {
        return $this->hasMany(SharedEnvironmentVariable::class)->where('type', 'team');
    }

    /**
     * @return BelongsToMany<User, $this, TeamUserPivot>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user', 'team_id', 'user_id')
            ->using(TeamUserPivot::class)
            ->withPivot('role');
    }

    /**
     * @return HasManyThrough<Application, Project, $this>
     */
    public function applications(): HasManyThrough
    {
        return $this->hasManyThrough(Application::class, Project::class);
    }

    /**
     * @return HasMany<TeamInvitation, $this>
     */
    public function invitations(): HasMany
    {
        return $this->hasMany(TeamInvitation::class);
    }

    public function isEmpty(): bool
    {
        if ($this->projects()->count() === 0 && $this->servers()->count() === 0 && $this->privateKeys()->count() === 0 && $this->sources()->count() === 0) {
            return true;
        }

        return false;
    }

    /**
     * @return HasMany<Project, $this>
     */
    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    /**
     * @return HasMany<Server, $this>
     */
    public function servers(): HasMany
    {
        return $this->hasMany(Server::class);
    }

    /**
     * @return HasMany<PrivateKey, $this>
     */
    public function privateKeys(): HasMany
    {
        return $this->hasMany(PrivateKey::class);
    }

    /**
     * @return HasMany<CloudProviderToken, $this>
     */
    public function cloudProviderTokens(): HasMany
    {
        return $this->hasMany(CloudProviderToken::class);
    }

    /**
     * @return SupportCollection<int, GithubApp|GitlabApp>
     */
    public function sources(): SupportCollection
    {
        /** @var SupportCollection<int, GithubApp|GitlabApp> $sources */
        $sources = collect();
        $github_apps = GithubApp::where(function ($query) {
            $query->where(function ($q) {
                $q->where('team_id', $this->id)
                    ->orWhere('is_system_wide', true);
            })->where('is_public', false);
        })->get();

        $gitlab_apps = GitlabApp::where(function ($query) {
            $query->where(function ($q) {
                $q->where('team_id', $this->id)
                    ->orWhere('is_system_wide', true);
            })->where('is_public', false);
        })->get();

        return $sources->merge($github_apps)->merge($gitlab_apps);
    }

    /**
     * @return HasMany<S3Storage, $this>
     */
    public function s3s(): HasMany
    {
        return $this->hasMany(S3Storage::class)->where('is_usable', true);
    }

    /**
     * @return HasOne<EmailNotificationSettings, $this>
     */
    public function emailNotificationSettings(): HasOne
    {
        return $this->hasOne(EmailNotificationSettings::class);
    }

    /**
     * @return HasOne<DiscordNotificationSettings, $this>
     */
    public function discordNotificationSettings(): HasOne
    {
        return $this->hasOne(DiscordNotificationSettings::class);
    }

    /**
     * @return HasOne<TelegramNotificationSettings, $this>
     */
    public function telegramNotificationSettings(): HasOne
    {
        return $this->hasOne(TelegramNotificationSettings::class);
    }

    /**
     * @return HasOne<SlackNotificationSettings, $this>
     */
    public function slackNotificationSettings(): HasOne
    {
        return $this->hasOne(SlackNotificationSettings::class);
    }

    /**
     * @return HasOne<PushoverNotificationSettings, $this>
     */
    public function pushoverNotificationSettings(): HasOne
    {
        return $this->hasOne(PushoverNotificationSettings::class);
    }

    /**
     * @return HasOne<WebhookNotificationSettings, $this>
     */
    public function webhookNotificationSettings(): HasOne
    {
        return $this->hasOne(WebhookNotificationSettings::class);
    }
}
