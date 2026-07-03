<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * @property-read Collection<int, Application> $applications
 * @property-read Team|null $team
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

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function applications(): MorphMany
    {
        return $this->morphMany(Application::class, 'source');
    }

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
