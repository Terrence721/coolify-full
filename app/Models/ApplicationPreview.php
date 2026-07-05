<?php

declare(strict_types=1);

namespace App\Models;

use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

/**
 * @property int $id
 * @property string $uuid
 * @property int $pull_request_id
 * @property string $pull_request_html_url
 * @property string|null $pull_request_issue_comment_id
 * @property string|null $fqdn
 * @property string $status
 * @property int $application_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $git_type
 * @property string|null $docker_compose_domains
 * @property string $last_online_at
 * @property Carbon|null $deleted_at
 * @property string|null $docker_registry_image_tag
 * @property-read Application|null $application
 * @property-read mixed $image
 * @property-read Collection<int, LocalPersistentVolume> $persistentStorages
 * @property-read int|null $persistent_storages_count
 * @property-read mixed $sanitized_name
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereApplicationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereDockerComposeDomains($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereDockerRegistryImageTag($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereFqdn($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereGitType($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereLastOnlineAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview wherePullRequestHtmlUrl($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview wherePullRequestId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview wherePullRequestIssueCommentId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview whereUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|ApplicationPreview withoutTrashed()
 *
 * @mixin \Eloquent
 */
class ApplicationPreview extends BaseModel
{
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'application_id',
        'pull_request_id',
        'pull_request_html_url',
        'pull_request_issue_comment_id',
        'fqdn',
        'status',
        'git_type',
        'docker_compose_domains',
        'docker_registry_image_tag',
        'last_online_at',
    ];

    protected $casts = [
        'pull_request_id' => 'integer',
    ];

    protected static function booted()
    {
        static::forceDeleting(function ($preview) {
            $server = $preview->application->destination->server;
            $application = $preview->application;

            if (data_get($preview, 'application.build_pack') === 'dockercompose') {
                // Docker Compose volume and network cleanup
                $composeFile = $application->parse(pull_request_id: $preview->pull_request_id);
                $volumes = data_get($composeFile, 'volumes');
                $networks = data_get($composeFile, 'networks');
                $networkKeys = collect($networks)->keys();
                $volumeKeys = collect($volumes)->keys();
                $volumeKeys->each(function ($key) use ($server) {
                    if (! preg_match(ValidationPatterns::VOLUME_NAME_PATTERN, $key)) {
                        return;
                    }
                    instant_remote_process(['docker volume rm -f '.escapeshellarg($key)], $server, false);
                });
                $networkKeys->each(function ($key) use ($server) {
                    if (! preg_match(ValidationPatterns::DOCKER_NETWORK_PATTERN, $key)) {
                        return;
                    }
                    $k = escapeshellarg($key);
                    instant_remote_process(["docker network disconnect {$k} coolify-proxy"], $server, false);
                    instant_remote_process(["docker network rm {$k}"], $server, false);
                });
            } else {
                // Regular application volume cleanup
                $persistentStorages = $preview->persistentStorages()->get() ?? collect();
                if ($persistentStorages->count() > 0) {
                    foreach ($persistentStorages as $storage) {
                        instant_remote_process(['docker volume rm -f '.escapeshellarg($storage->name)], $server, false);
                    }
                }
            }

            // Clean up persistent storage records
            $preview->persistentStorages()->delete();
        });
        static::saving(function ($preview) {
            if ($preview->isDirty('status')) {
                $preview->last_online_at = now();
            }
        });
    }

    public static function findPreviewByApplicationAndPullId(int $application_id, int $pull_request_id)
    {
        return self::where('application_id', $application_id)->where('pull_request_id', $pull_request_id)->firstOrFail();
    }

    public function isRunning()
    {
        return (bool) str($this->status)->startsWith('running');
    }

    public function application()
    {
        return $this->belongsTo(Application::class);
    }

    public function persistentStorages()
    {
        return $this->morphMany(LocalPersistentVolume::class, 'resource');
    }

    public function generate_preview_fqdn()
    {
        if ($this->application->fqdn) {
            if (str($this->application->fqdn)->contains(',')) {
                $url = Url::fromString(str($this->application->fqdn)->explode(',')[0]);
            } else {
                $url = Url::fromString($this->application->fqdn);
            }
            $template = $this->application->preview_url_template;
            $host = $url->getHost();
            $schema = $url->getScheme();
            $portInt = $url->getPort();
            $port = $portInt !== null ? ':'.$portInt : '';
            $urlPath = $url->getPath();
            $path = ($urlPath !== '' && $urlPath !== '/') ? $urlPath : '';
            $random = (string) new Cuid2;
            $preview_fqdn = str_replace('{{random}}', $random, $template);
            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
            $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
            $preview_fqdn = "$schema://$preview_fqdn{$port}{$path}";
            $this->fqdn = $preview_fqdn;
            $this->save();
        }

        return $this;
    }

    public function generate_preview_fqdn_compose()
    {
        $services = collect(json_decode($this->application->docker_compose_domains)) ?? collect();
        $docker_compose_domains = data_get($this, 'docker_compose_domains');
        $docker_compose_domains = json_decode($docker_compose_domains, true) ?? [];

        // Get all services from the parsed compose file to ensure all services have entries
        $parsedServices = $this->application->parse(pull_request_id: $this->pull_request_id);
        if (isset($parsedServices['services'])) {
            foreach ($parsedServices['services'] as $serviceName => $service) {
                if (! isDatabaseImage(data_get($service, 'image'))) {
                    // Remove PR suffix from service name to get original service name
                    $originalServiceName = str($serviceName)->replaceLast('-pr-'.$this->pull_request_id, '')->toString();

                    // Ensure all services have an entry, even if empty
                    if (! $services->has($originalServiceName)) {
                        $services->put($originalServiceName, ['domain' => '']);
                    }
                }
            }
        }

        foreach ($services as $service_name => $service_config) {
            $domain_string = data_get($service_config, 'domain');

            // If domain string is empty or null, don't auto-generate domain
            // Only generate domains when main app already has domains set
            if (empty($domain_string)) {
                // Ensure service has an empty domain entry for form binding
                $docker_compose_domains[$service_name]['domain'] = '';

                continue;
            }

            $service_domains = str($domain_string)->explode(',')->map(fn ($d) => trim($d));

            $preview_domains = [];
            foreach ($service_domains as $domain) {
                if (empty($domain)) {
                    continue;
                }

                $url = Url::fromString($domain);
                $template = $this->application->preview_url_template;
                $host = $url->getHost();
                $schema = $url->getScheme();
                $portInt = $url->getPort();
                $port = $portInt !== null ? ':'.$portInt : '';
                $urlPath = $url->getPath();
                $path = ($urlPath !== '' && $urlPath !== '/') ? $urlPath : '';
                $random = (string) new Cuid2;
                $preview_fqdn = str_replace('{{random}}', $random, $template);
                $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                $preview_fqdn = str_replace('{{pr_id}}', $this->pull_request_id, $preview_fqdn);
                $preview_fqdn = "$schema://$preview_fqdn{$port}{$path}";
                $preview_domains[] = $preview_fqdn;
            }

            if (! empty($preview_domains)) {
                $docker_compose_domains[$service_name]['domain'] = implode(',', $preview_domains);
            } else {
                // Ensure service has an empty domain entry for form binding
                $docker_compose_domains[$service_name]['domain'] = '';
            }
        }

        $this->docker_compose_domains = json_encode($docker_compose_domains);

        // Populate fqdn from generated domains so webhook notifications can read it
        $allDomains = collect($docker_compose_domains)
            ->pluck('domain')
            ->filter(fn ($d) => ! empty($d))
            ->flatMap(fn ($d) => explode(',', $d))
            ->implode(',');

        $this->fqdn = ! empty($allDomains) ? $allDomains : null;

        $this->save();
    }
}
