<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\Team;
use App\Models\User;
use App\Support\DatabaseEngineRegistry;
use Illuminate\Support\Facades\Cache;

/**
 * Shared search/creatable-item data behind both the still-Livewire GlobalSearch (kept alive for
 * Boarding\Index/Server\Show, the two pages still on layouts/app.blade.php) and the React
 * GlobalSearchController — split out here rather than duplicated so the two stay behaviorally
 * identical while both are live, matching this migration's established "extract on 2nd consumer"
 * pattern for shared tabs/concerns.
 */
class GlobalSearchService
{
    public static function getCacheKey(int $teamId): string
    {
        return 'global_search_items_'.$teamId;
    }

    public static function clearTeamCache(int $teamId): void
    {
        Cache::forget(self::getCacheKey($teamId));
    }

    public function loadSearchableItems(Team $team): array
    {
        return Cache::remember(self::getCacheKey($team->id), 300, function () use ($team) {
            $items = collect();

            $applications = Application::ownedByCurrentTeam()
                ->with(['environment.project', 'previews:id,application_id,pull_request_id'])
                ->get()
                ->map(function ($app) {
                    $fqdns = collect([]);

                    if ($app->fqdn) {
                        $fqdns = collect(explode(',', $app->fqdn))->map(fn ($fqdn) => trim($fqdn));
                    }

                    if ($app->build_pack === 'dockercompose' && $app->docker_compose_domains) {
                        try {
                            $composeDomains = json_decode($app->docker_compose_domains, true);
                            if (is_array($composeDomains)) {
                                foreach ($composeDomains as $serviceName => $domains) {
                                    if (is_array($domains)) {
                                        $fqdns = $fqdns->merge($domains);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore JSON parsing errors
                        }
                    }

                    $fqdnsString = $fqdns->implode(' ');

                    $prSearchTerms = '';
                    if ($app->preview_enabled ?? false) {
                        $prIds = collect($app->previews ?? [])
                            ->pluck('pull_request_id')
                            ->map(fn ($id) => "pr-{$id} pr{$id} {$id}")
                            ->implode(' ');
                        $prSearchTerms = $prIds;
                    }

                    return [
                        'id' => $app->id,
                        'name' => $app->name,
                        'type' => 'application',
                        'uuid' => $app->uuid,
                        'description' => $app->description,
                        'link' => $app->link(),
                        'project' => $app->environment->project->name ?? null,
                        'environment' => $app->environment->name ?? null,
                        'fqdns' => $fqdns->take(2)->implode(', '),
                        'search_text' => strtolower($app->name.' '.$app->description.' '.$fqdnsString.' '.$app->uuid.' '.$prSearchTerms.' application applications app apps'),
                    ];
                });

            $services = Service::ownedByCurrentTeam()
                ->with(['environment.project', 'applications', 'databases'])
                ->get()
                ->map(function ($service) {
                    $fqdns = collect([]);
                    foreach ($service->applications as $app) {
                        if ($app->fqdn) {
                            $appFqdns = collect(explode(',', $app->fqdn))->map(fn ($fqdn) => trim($fqdn));
                            $fqdns = $fqdns->merge($appFqdns);
                        }
                    }
                    $fqdnsString = $fqdns->implode(' ');

                    $serviceAppNames = collect($service->applications ?? [])->pluck('name')->implode(' ');
                    $serviceDbNames = collect($service->databases ?? [])->pluck('name')->implode(' ');

                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'type' => 'service',
                        'uuid' => $service->uuid,
                        'description' => $service->description,
                        'link' => $service->link(),
                        'project' => $service->environment->project->name ?? null,
                        'environment' => $service->environment->name ?? null,
                        'fqdns' => $fqdns->take(2)->implode(', '),
                        'search_text' => strtolower($service->name.' '.$service->description.' '.$fqdnsString.' '.$service->uuid.' '.$serviceAppNames.' '.$serviceDbNames.' service services'),
                    ];
                });

            $databases = collect();

            foreach (DatabaseEngineRegistry::all() as $engine) {
                $modelClass = $engine->modelClass;
                $databases = $databases->merge(
                    $modelClass::ownedByCurrentTeam()
                        ->with(['environment.project'])
                        ->get()
                        ->map(function ($db) use ($engine) {
                            return [
                                'id' => $db->id,
                                'name' => $db->name,
                                'type' => 'database',
                                'subtype' => $engine->type,
                                'uuid' => $db->uuid,
                                'description' => $db->description,
                                'link' => $db->link(),
                                'project' => $db->environment->project->name ?? null,
                                'environment' => $db->environment->name ?? null,
                                'search_text' => strtolower($db->name.' '.$db->uuid.' '.$engine->type.' '.$db->description.' database databases db'),
                            ];
                        })
                );
            }

            $servers = Server::ownedByCurrentTeam()
                ->get()
                ->map(function ($server) {
                    return [
                        'id' => $server->id,
                        'name' => $server->name,
                        'type' => 'server',
                        'uuid' => $server->uuid,
                        'description' => $server->description,
                        'link' => $server->url(),
                        'project' => null,
                        'environment' => null,
                        'search_text' => strtolower($server->name.' '.$server->ip.' '.$server->description.' server servers'),
                    ];
                });

            $projects = Project::ownedByCurrentTeam()
                ->withCount(['environments', 'applications', 'services'])
                ->get()
                ->map(function ($project) {
                    $resourceCount = $project->applications_count + $project->services_count;
                    $resourceSummary = $resourceCount > 0
                        ? "{$resourceCount} resource".($resourceCount !== 1 ? 's' : '')
                        : 'No resources';

                    return [
                        'id' => $project->id,
                        'name' => $project->name,
                        'type' => 'project',
                        'uuid' => $project->uuid,
                        'description' => $project->description,
                        'link' => $project->navigateTo(),
                        'project' => null,
                        'environment' => null,
                        'resource_count' => $resourceSummary,
                        'environment_count' => $project->environments_count,
                        'search_text' => strtolower($project->name.' '.$project->description.' project projects'),
                    ];
                });

            $environments = Environment::ownedByCurrentTeam()
                ->with('project')
                ->withCount(['applications', 'services'])
                ->get()
                ->map(function ($environment) {
                    $resourceCount = $environment->applications_count + $environment->services_count;
                    $resourceSummary = $resourceCount > 0
                        ? "{$resourceCount} resource".($resourceCount !== 1 ? 's' : '')
                        : 'No resources';

                    $descriptionParts = [];
                    if ($environment->project) {
                        $descriptionParts[] = "Project: {$environment->project->name}";
                    }
                    if ($environment->description) {
                        $descriptionParts[] = $environment->description;
                    }
                    if (empty($descriptionParts)) {
                        $descriptionParts[] = $resourceSummary;
                    }

                    return [
                        'id' => $environment->id,
                        'name' => $environment->name,
                        'type' => 'environment',
                        'uuid' => $environment->uuid,
                        'description' => implode(' • ', $descriptionParts),
                        'link' => route('project.resource.index', [
                            'project_uuid' => $environment->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ]),
                        'project' => $environment->project->name ?? null,
                        'environment' => null,
                        'resource_count' => $resourceSummary,
                        'search_text' => strtolower($environment->name.' '.$environment->description.' '.$environment->project->name.' environment'),
                    ];
                });

            $navigation = collect([
                [
                    'name' => 'Dashboard',
                    'type' => 'navigation',
                    'description' => 'Go to main dashboard',
                    'link' => route('dashboard'),
                    'search_text' => 'dashboard home main overview',
                ],
                [
                    'name' => 'Servers',
                    'type' => 'navigation',
                    'description' => 'View all servers',
                    'link' => route('server.index'),
                    'search_text' => 'servers all list view',
                ],
                [
                    'name' => 'Projects',
                    'type' => 'navigation',
                    'description' => 'View all projects',
                    'link' => route('project.index'),
                    'search_text' => 'projects all list view',
                ],
                [
                    'name' => 'Destinations',
                    'type' => 'navigation',
                    'description' => 'View all destinations',
                    'link' => route('destination.index'),
                    'search_text' => 'destinations docker networks',
                ],
                [
                    'name' => 'Security',
                    'type' => 'navigation',
                    'description' => 'Manage private keys and API tokens',
                    'link' => route('security.private-key.index'),
                    'search_text' => 'security private keys ssh api tokens cloud-init scripts',
                ],
                [
                    'name' => 'Cloud-Init Scripts',
                    'type' => 'navigation',
                    'description' => 'Manage reusable cloud-init scripts',
                    'link' => route('security.cloud-init-scripts'),
                    'search_text' => 'cloud-init scripts cloud init cloudinit initialization startup server setup',
                ],
                [
                    'name' => 'Sources',
                    'type' => 'navigation',
                    'description' => 'Manage GitHub apps and Git sources',
                    'link' => route('source.all'),
                    'search_text' => 'sources github apps git repositories',
                ],
                [
                    'name' => 'Storages',
                    'type' => 'navigation',
                    'description' => 'Manage S3 storage for backups',
                    'link' => route('storage.index'),
                    'search_text' => 'storages s3 backups',
                ],
                [
                    'name' => 'Shared Variables',
                    'type' => 'navigation',
                    'description' => 'View all shared variables',
                    'link' => route('shared-variables.index'),
                    'search_text' => 'shared variables environment all',
                ],
                [
                    'name' => 'Team Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage team-wide shared variables',
                    'link' => route('shared-variables.team.index'),
                    'search_text' => 'shared variables team environment',
                ],
                [
                    'name' => 'Project Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage project shared variables',
                    'link' => route('shared-variables.project.index'),
                    'search_text' => 'shared variables project environment',
                ],
                [
                    'name' => 'Environment Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage environment shared variables',
                    'link' => route('shared-variables.environment.index'),
                    'search_text' => 'shared variables environment',
                ],
                [
                    'name' => 'Tags',
                    'type' => 'navigation',
                    'description' => 'View resources by tags',
                    'link' => route('tags.show'),
                    'search_text' => 'tags labels organize',
                ],
                [
                    'name' => 'Terminal',
                    'type' => 'navigation',
                    'description' => 'Access server terminal',
                    'link' => route('terminal'),
                    'search_text' => 'terminal ssh console shell command line',
                ],
                [
                    'name' => 'Profile',
                    'type' => 'navigation',
                    'description' => 'Manage your profile and preferences',
                    'link' => route('profile'),
                    'search_text' => 'profile account user settings preferences',
                ],
                [
                    'name' => 'Team',
                    'type' => 'navigation',
                    'description' => 'Manage team members and settings',
                    'link' => route('team.index'),
                    'search_text' => 'team settings members users invitations',
                ],
                [
                    'name' => 'Notifications',
                    'type' => 'navigation',
                    'description' => 'Configure email, Discord, Telegram notifications',
                    'link' => route('notifications.email'),
                    'search_text' => 'notifications alerts email discord telegram slack pushover',
                ],
            ]);

            if (! isCloud() && $team->id === 0) {
                $navigation->push([
                    'name' => 'Settings',
                    'type' => 'navigation',
                    'description' => 'Instance settings and configuration',
                    'link' => route('settings.index'),
                    'search_text' => 'settings configuration instance',
                ]);
            }

            $items = $items->merge($navigation)
                ->merge($applications)
                ->merge($services)
                ->merge($databases)
                ->merge($servers)
                ->merge($projects)
                ->merge($environments);

            return $items->toArray();
        });
    }

    public function loadServices(User $user): array
    {
        if (! $user->can('createAnyResource')) {
            return [];
        }

        $allServices = get_service_templates();
        $items = collect();

        foreach ($allServices as $serviceKey => $service) {
            $items->push([
                'name' => str($serviceKey)->headline()->toString(),
                'description' => data_get($service, 'slogan', 'Deploy '.str($serviceKey)->headline()),
                'type' => 'one-click-service-'.$serviceKey,
                'category' => 'Services',
                'resourceType' => 'service',
                'logo' => data_get($service, 'logo'),
            ] + array_filter([
                'amd_only' => data_get($service, 'amd_only') ? true : null,
                'arm_only' => data_get($service, 'arm_only') ? true : null,
            ]));
        }

        return $items->toArray();
    }

    public function loadCreatableItems(User $user, array $services): array
    {
        $items = collect();

        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'Project',
                'description' => 'Create a new project to organize your resources',
                'quickcommand' => '(type: new project)',
                'type' => 'project',
                'category' => 'Quick Actions',
                'component' => 'project.add-empty',
            ]);
        }

        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Server',
                'description' => 'Add a new server to deploy your applications',
                'quickcommand' => '(type: new server)',
                'type' => 'server',
                'category' => 'Quick Actions',
                'component' => 'server.create',
            ]);
        }

        $items->push([
            'name' => 'Team',
            'description' => 'Create a new team to collaborate with others',
            'quickcommand' => '(type: new team)',
            'type' => 'team',
            'category' => 'Quick Actions',
            'component' => 'team.create',
        ]);

        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'S3 Storage',
                'description' => 'Add S3 storage for backups and file uploads',
                'quickcommand' => '(type: new storage)',
                'type' => 'storage',
                'category' => 'Quick Actions',
                'component' => 'storage.create',
            ]);
        }

        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Private Key',
                'description' => 'Add an SSH private key for server access',
                'quickcommand' => '(type: new private key)',
                'type' => 'private-key',
                'category' => 'Quick Actions',
                'component' => 'security.private-key.create',
            ]);
        }

        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'GitHub App',
                'description' => 'Connect a GitHub app for source control',
                'quickcommand' => '(type: new github)',
                'type' => 'source',
                'category' => 'Quick Actions',
                'link' => route('source.all', ['create' => 1]),
            ]);
        }

        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'Public Git Repository',
                'description' => 'Deploy from any public Git repository',
                'quickcommand' => '(type: new public)',
                'type' => 'public',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Private Repository (GitHub App)',
                'description' => 'Deploy private repositories through GitHub Apps',
                'quickcommand' => '(type: new private github)',
                'type' => 'private-gh-app',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Private Repository (Deploy Key)',
                'description' => 'Deploy private repositories with a deploy key',
                'quickcommand' => '(type: new private deploy)',
                'type' => 'private-deploy-key',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Dockerfile',
                'description' => 'Deploy a simple Dockerfile without Git',
                'quickcommand' => '(type: new dockerfile)',
                'type' => 'dockerfile',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Docker Compose',
                'description' => 'Deploy complex applications with Docker Compose',
                'quickcommand' => '(type: new compose)',
                'type' => 'docker-compose-empty',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Docker Image',
                'description' => 'Deploy an existing Docker image from any registry',
                'quickcommand' => '(type: new image)',
                'type' => 'docker-image',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);
        }

        if ($user->can('createAnyResource')) {
            foreach (DatabaseEngineRegistry::all() as $engine) {
                $items->push([
                    'name' => $engine->displayName,
                    'description' => $engine->description,
                    'quickcommand' => "(type: new {$engine->type})",
                    'type' => $engine->type,
                    'category' => 'Databases',
                    'resourceType' => 'database',
                ]);
            }
        }

        $items = $items->merge(collect($services));

        return $items->toArray();
    }

    public function canCreateResource(string $type, User $user): bool
    {
        if (in_array($type, ['server', 'storage', 'private-key'])) {
            return $user->isAdmin() || $user->isOwner();
        }

        if ($type === 'team') {
            return true;
        }

        if (in_array($type, [
            'project', 'source',
            'public', 'private-gh-app', 'private-deploy-key',
            'dockerfile', 'docker-compose-empty', 'docker-image',
            ...DatabaseEngineRegistry::types(),
        ]) || str_starts_with($type, 'one-click-service-')) {
            return $user->can('createAnyResource');
        }

        return false;
    }

    public function detectSpecificResource(string $query, User $user): ?string
    {
        $resourceMap = [
            'new project' => 'project',
            'new server' => 'server',
            'new team' => 'team',
            'new storage' => 'storage',
            'new s3' => 'storage',
            'new private key' => 'private-key',
            'new privatekey' => 'private-key',
            'new key' => 'private-key',
            'new github app' => 'source',
            'new github' => 'source',
            'new source' => 'source',

            'new public' => 'public',
            'new public git' => 'public',
            'new public repo' => 'public',
            'new public repository' => 'public',
            'new private github' => 'private-gh-app',
            'new private gh' => 'private-gh-app',
            'new private deploy' => 'private-deploy-key',
            'new deploy key' => 'private-deploy-key',

            'new dockerfile' => 'dockerfile',
            'new docker compose' => 'docker-compose-empty',
            'new compose' => 'docker-compose-empty',
            'new docker image' => 'docker-image',
            'new image' => 'docker-image',

            'new postgres' => 'postgresql',
            'new mongo' => 'mongodb',
        ];

        foreach (DatabaseEngineRegistry::types() as $type) {
            $resourceMap["new {$type}"] = $type;
        }

        foreach ($resourceMap as $command => $type) {
            if ($query === $command) {
                if ($this->canCreateResource($type, $user)) {
                    return $type;
                }
            }
        }

        return null;
    }
}
