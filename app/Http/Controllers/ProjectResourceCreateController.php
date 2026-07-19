<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Services\DockerImageParser;
use App\Support\ValidationPatterns;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

/**
 * React port of App\Livewire\Project\New\Select and App\Livewire\Project\Resource\Create — the
 * "+ New" resource wizard (type -> server -> destination -> [postgres version] -> create). Every
 * step is a fresh GET to `project.resource.create` with the accumulated choices on the query
 * string, mirroring the original's own step transitions (each of which was itself a Livewire
 * round trip / a `whatToDoNext()` redirect carrying the same params forward).
 *
 * Only the 3 GitHub-dependent flows (public/private-gh-app/private-deploy-key) are NOT handled
 * here — those redirect to `project.resource.create.git`, handled by
 * ProjectResourceGitCreateController (their own React/Inertia port).
 *
 * Two dead code paths found in the original were deliberately not ported: the `'existing-postgresql'`
 * step (its blade posts to `addExistingPostgresql()`, which doesn't exist anywhere in `Select.php`,
 * and no UI tile ever sets that type in the first place) and `App\Livewire\Project\New\EmptyProject`
 * (zero production callers anywhere in the codebase).
 */
class ProjectResourceCreateController extends Controller
{
    private const GIT_TYPES = ['public', 'private-gh-app', 'private-deploy-key'];

    private const POSTGRESQL_VERSIONS = [
        'postgres:18-alpine' => ['name' => 'PostgreSQL 18 (default)', 'description' => 'PostgreSQL is a powerful, open-source object-relational database system (no extensions).', 'url' => 'https://hub.docker.com/_/postgres/'],
        'postgres:17-alpine' => ['name' => 'PostgreSQL 17', 'description' => 'PostgreSQL is a powerful, open-source object-relational database system (no extensions).', 'url' => 'https://hub.docker.com/_/postgres/'],
        'postgres:16-alpine' => ['name' => 'PostgreSQL 16', 'description' => 'PostgreSQL is a powerful, open-source object-relational database system (no extensions).', 'url' => 'https://hub.docker.com/_/postgres/'],
        'supabase/postgres:17.4.1.032' => ['name' => 'Supabase PostgreSQL (with extensions)', 'description' => 'Supabase is a modern, open-source alternative to PostgreSQL with lots of extensions.', 'url' => 'https://github.com/supabase/postgres'],
        'postgis/postgis:17-3.5-alpine' => ['name' => 'PostGIS (AMD only)', 'description' => 'PostGIS is a PostgreSQL extension for geographic objects.', 'url' => 'https://github.com/postgis/docker-postgis'],
        'pgvector/pgvector:pg18' => ['name' => 'PGVector (18)', 'description' => 'PGVector is a PostgreSQL extension for vector data types.', 'url' => 'https://github.com/pgvector/pgvector'],
        'pgvector/pgvector:pg17' => ['name' => 'PGVector (17)', 'description' => 'PGVector is a PostgreSQL extension for vector data types.', 'url' => 'https://github.com/pgvector/pgvector'],
    ];

    public function index(Request $request, string $project_uuid, string $environment_uuid): Response|RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $environment = $project->environments()->where('uuid', $environment_uuid)->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }

        $type = $request->query('type');
        if (! is_string($type) || $type === '') {
            return $this->renderTypeStep($project, $environment);
        }
        $type = str($type)->lower()->slug()->value();

        $restrictToStandaloneServers = in_array($type, DATABASE_TYPES, true)
            || str($type)->startsWith('one-click-service-')
            || $type === 'docker-compose-empty';

        $allUsableServers = Server::isUsable()->get()->sortBy('name')->values();
        $onlyBuildServerAvailable = $allUsableServers->isNotEmpty() && $allUsableServers->every(fn (Server $server) => $server->isBuildServer());

        $servers = $restrictToStandaloneServers
            ? $allUsableServers->reject(fn (Server $server) => $server->settings->is_swarm_worker || $server->settings->is_swarm_manager || $server->settings->is_build_server)->values()
            : $allUsableServers;

        $server = null;
        $server_id = $request->query('server_id');
        if (is_string($server_id) && $server_id !== '') {
            $server = $servers->firstWhere('id', (int) $server_id);
            if (! $server) {
                return redirect()->route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'type' => $type]);
            }
        } elseif ($servers->count() === 1) {
            $server = $servers->first();
        }

        if (! $server) {
            return $this->renderServersStep($project, $environment, $type, $servers, $onlyBuildServerAvailable);
        }

        $destinations = $server->isSwarm() ? $server->swarmDockers : $server->standaloneDockers;

        $destination = null;
        $destination_uuid = $request->query('destination');
        if (is_string($destination_uuid) && $destination_uuid !== '') {
            $destination = $destinations->firstWhere('uuid', $destination_uuid);
            if (! $destination) {
                return redirect()->route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'type' => $type, 'server_id' => $server->id]);
            }
        } elseif ($destinations->count() === 1) {
            $destination = $destinations->first();
        }

        if (! $destination) {
            return $this->renderDestinationsStep($project, $environment, $type, $server, $destinations);
        }

        $database_image = $request->query('database_image');
        if ($type === 'postgresql' && (! is_string($database_image) || $database_image === '')) {
            return $this->renderPostgresqlVersionStep($project, $environment, $type, $server, $destination);
        }

        if (in_array($type, DATABASE_TYPES, true)) {
            return $this->createDatabase($project, $environment, $type, $destination, is_string($database_image) ? $database_image : null);
        }

        if (str($type)->startsWith('one-click-service-')) {
            return $this->createOneClickService($project, $environment, $type, $server, $destination);
        }

        if (in_array($type, self::GIT_TYPES, true)) {
            return redirect()->route('project.resource.create.git', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
                'type' => $type,
                'destination' => $destination->uuid,
                'server_id' => $server->id,
            ]);
        }

        return match ($type) {
            'dockerfile' => $this->renderDockerfileForm($project, $environment, $destination, $server),
            'docker-image' => $this->renderDockerImageForm($project, $environment, $destination, $server),
            'docker-compose-empty' => $this->renderDockerComposeForm($project, $environment, $destination, $server),
            default => $this->renderTypeStep($project, $environment),
        };
    }

    public function storeDockerfile(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'dockerfile' => ['required', 'string'],
        ])->validate();

        $dockerfile = $validated['dockerfile'];
        $port = get_port_from_dockerfile($dockerfile) ?: 80;

        $application = Application::create([
            'name' => 'dockerfile-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerfile',
            'dockerfile' => $dockerfile,
            'ports_exposes' => $port,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'health_check_enabled' => false,
            'source_id' => 0,
            'source_type' => GithubApp::class,
        ]);

        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->update([
            'name' => 'dockerfile-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        $application->parseHealthcheckFromDockerfile(dockerfile: $dockerfile, isInit: true);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function storeDockerImage(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'imageName' => ValidationPatterns::dockerImageNameRules(required: true),
            'imageTag' => ValidationPatterns::dockerImageTagRules(),
            'imageSha256' => ['nullable', 'string', 'regex:/^[a-f0-9]{64}$/i'],
        ])->validate();

        $imageName = $validated['imageName'];
        $imageTag = $validated['imageTag'] ?? '';
        $imageSha256 = $validated['imageSha256'] ?? '';

        if ($imageTag && $imageSha256) {
            return back()->withErrors([
                'imageTag' => 'Provide either a tag or SHA256 digest, not both.',
                'imageSha256' => 'Provide either a tag or SHA256 digest, not both.',
            ])->withInput();
        }

        if ($imageSha256) {
            $sha256Hash = preg_replace('/^sha256:/i', '', trim($imageSha256));
            $dockerImage = $imageName.'@sha256:'.$sha256Hash;
        } elseif ($imageTag) {
            $dockerImage = $imageName.':'.$imageTag;
        } else {
            $dockerImage = $imageName.':latest';
        }

        $parser = new DockerImageParser;
        $parser->parse($dockerImage);

        $parsedImageName = $parser->getFullImageNameWithoutTag();
        if ($parser->isImageHash() && ! str_ends_with($parsedImageName, '@sha256')) {
            $parsedImageName .= '@sha256';
        }
        $parsedImageTag = $parser->isImageHash() ? 'sha256-'.$parser->getTag() : $parser->getTag();

        $application = Application::create([
            'name' => 'docker-image-'.new Cuid2,
            'repository_project_id' => 0,
            'git_repository' => 'coollabsio/coolify',
            'git_branch' => 'main',
            'build_pack' => 'dockerimage',
            'ports_exposes' => 80,
            'docker_registry_image_name' => $parsedImageName,
            'docker_registry_image_tag' => $parsedImageTag,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'health_check_enabled' => false,
        ]);

        $fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->update([
            'name' => 'docker-image-'.$application->uuid,
            'fqdn' => $fqdn,
        ]);

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function storeDockerCompose(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'dockerComposeRaw' => ['required', 'string'],
        ])->validate();

        $dockerComposeRaw = Yaml::dump(
            Yaml::parse($validated['dockerComposeRaw']),
            10,
            2,
            Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK
        );

        validateDockerComposeForInjection($dockerComposeRaw);

        $service = Service::create([
            'docker_compose_raw' => $dockerComposeRaw,
            'environment_id' => $environment->id,
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ]);

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        return redirect()->route('project.service.configuration', [
            'service_uuid' => $service->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    /**
     * @return array{0: Project, 1: Environment, 2: StandaloneDocker|SwarmDocker}
     */
    private function resolveDestinationForSubmit(Request $request, string $project_uuid, string $environment_uuid): array
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        $destination = find_destination_for_current_team($request->query('destination'));
        if (! $destination) {
            abort(404);
        }

        return [$project, $environment, $destination];
    }

    private function createDatabase(Project $project, Environment $environment, string $type, StandaloneDocker|SwarmDocker $destination, ?string $database_image): RedirectResponse
    {
        $database = match ($type) {
            'postgresql' => create_standalone_postgresql($environment->id, $destination, databaseImage: $database_image ?: 'postgres:16-alpine'),
            'redis' => create_standalone_redis($environment->id, $destination),
            'mongodb' => create_standalone_mongodb($environment->id, $destination),
            'mysql' => create_standalone_mysql($environment->id, $destination),
            'mariadb' => create_standalone_mariadb($environment->id, $destination),
            'keydb' => create_standalone_keydb($environment->id, $destination),
            'dragonfly' => create_standalone_dragonfly($environment->id, $destination),
            'clickhouse' => create_standalone_clickhouse($environment->id, $destination),
            default => null,
        };

        if (! $database) {
            return redirect()->route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]);
        }

        return redirect()->route('project.database.configuration', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
            'database_uuid' => $database->uuid,
        ]);
    }

    private function createOneClickService(Project $project, Environment $environment, string $type, Server $server, StandaloneDocker|SwarmDocker $destination): RedirectResponse
    {
        $oneClickServiceName = str($type)->after('one-click-service-')->value();
        $services = get_service_templates();
        $oneClickService = data_get($services, "$oneClickServiceName.compose");
        $oneClickDotEnvs = data_get($services, "$oneClickServiceName.envs", null);

        if (! $oneClickService) {
            return redirect()->route('project.resource.create', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]);
        }

        if ($oneClickDotEnvs) {
            $oneClickDotEnvs = str(base64_decode($oneClickDotEnvs))->split('/\r\n|\r|\n/')->filter(fn ($value) => ! empty($value));
        }

        $service_payload = [
            'docker_compose_raw' => base64_decode($oneClickService),
            'environment_id' => $environment->id,
            'service_type' => $oneClickServiceName,
            'server_id' => $destination->server_id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ];
        if (in_array($oneClickServiceName, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK, true)) {
            data_set($service_payload, 'connect_to_docker_network', true);
        }

        $service = Service::create($service_payload);
        $service->name = "$oneClickServiceName-".$service->uuid;
        $service->save();

        if ($oneClickDotEnvs?->count() > 0) {
            $oneClickDotEnvs->each(function ($value) use ($service) {
                $key = str()->before($value, '=');
                $value = str(str()->after($value, '='));
                if ($value->isNotEmpty()) {
                    EnvironmentVariable::create([
                        'key' => $key,
                        'value' => $value,
                        'resourceable_id' => $service->id,
                        'resourceable_type' => $service->getMorphClass(),
                        'is_preview' => false,
                    ]);
                }
            });
        }

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        return redirect()->route('project.service.configuration', [
            'service_uuid' => $service->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    private function renderTypeStep(Project $project, Environment $environment): Response
    {
        $services = collect(get_service_templates())->map(function ($service, $key) {
            $default_logo = 'images/default.webp';
            $logo = data_get($service, 'logo', $default_logo);
            $local_logo_path = public_path($logo);

            return [
                'id' => (string) $key,
                'name' => str($key)->headline(),
                'logo' => asset($logo),
                'logo_github_url' => file_exists($local_logo_path)
                    ? 'https://raw.githubusercontent.com/coollabsio/coolify/refs/heads/main/public/'.$logo
                    : asset($default_logo),
            ] + (array) $service;
        })->values()->all();

        $categories = collect($services)
            ->pluck('category')
            ->filter()
            ->flatMap(fn ($category) => str_contains((string) $category, ',') ? array_map('trim', explode(',', $category)) : [$category])
            ->unique()
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        $gitBasedApplications = [
            ['id' => 'public', 'name' => 'Public Repository', 'description' => 'You can deploy any kind of public repositories from the supported git providers.', 'logo' => asset('svgs/git.svg')],
            ['id' => 'private-gh-app', 'name' => 'Private Repository (with GitHub App)', 'description' => 'You can deploy public & private repositories through your GitHub Apps.', 'logo' => asset('svgs/github.svg')],
            ['id' => 'private-deploy-key', 'name' => 'Private Repository (with Deploy Key)', 'description' => 'You can deploy private repositories with a deploy key.', 'logo' => asset('svgs/git.svg')],
        ];
        $dockerBasedApplications = [
            ['id' => 'dockerfile', 'name' => 'Dockerfile', 'description' => 'You can deploy a simple Dockerfile, without Git.', 'logo' => asset('svgs/docker.svg')],
            ['id' => 'docker-compose-empty', 'name' => 'Docker Compose Empty', 'description' => 'You can deploy complex application easily with Docker Compose, without Git.', 'logo' => asset('svgs/docker.svg')],
            ['id' => 'docker-image', 'name' => 'Docker Image', 'description' => 'You can deploy an existing Docker Image from any Registry, without Git.', 'logo' => asset('svgs/docker.svg')],
        ];
        $databases = collect(DATABASE_TYPES)->map(fn ($type) => ['id' => $type, 'name' => str($type)->headline()])->values()->all();

        return Inertia::render('Project/Resource/Create', [
            'step' => 'type',
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'environments' => $project->environments->map(fn (Environment $env) => ['uuid' => $env->uuid, 'name' => $env->name])->values(),
            'services' => $services,
            'categories' => $categories,
            'gitBasedApplications' => $gitBasedApplications,
            'dockerBasedApplications' => $dockerBasedApplications,
            'databases' => $databases,
        ]);
    }

    /**
     * @param  Collection<int, Server>  $servers
     */
    private function renderServersStep(Project $project, Environment $environment, string $type, Collection $servers, bool $onlyBuildServerAvailable): Response
    {
        return Inertia::render('Project/Resource/Create', [
            'step' => 'servers',
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'type' => $type,
            'servers' => $servers->map(fn (Server $server) => ['id' => $server->id, 'name' => $server->name, 'description' => $server->description])->values(),
            'onlyBuildServerAvailable' => $onlyBuildServerAvailable,
        ]);
    }

    /**
     * @param  EloquentCollection<int, StandaloneDocker>|EloquentCollection<int, SwarmDocker>  $destinations
     */
    private function renderDestinationsStep(Project $project, Environment $environment, string $type, Server $server, Collection $destinations): Response
    {
        return Inertia::render('Project/Resource/Create', [
            'step' => 'destinations',
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'type' => $type,
            'serverId' => $server->id,
            'isSwarm' => $server->isSwarm(),
            'destinations' => $destinations->map(fn ($destination) => [
                'uuid' => $destination->uuid,
                'name' => $destination->name,
                'network' => $destination->network,
            ])->values(),
        ]);
    }

    private function renderPostgresqlVersionStep(Project $project, Environment $environment, string $type, Server $server, StandaloneDocker|SwarmDocker $destination): Response
    {
        $versions = collect(self::POSTGRESQL_VERSIONS)->map(fn ($info, $image) => ['image' => $image, ...$info])->values();

        return Inertia::render('Project/Resource/Create', [
            'step' => 'select-postgresql-type',
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'type' => $type,
            'serverId' => $server->id,
            'destinationUuid' => $destination->uuid,
            'postgresqlVersions' => $versions,
        ]);
    }

    private function renderDockerfileForm(Project $project, Environment $environment, StandaloneDocker|SwarmDocker $destination, Server $server): Response
    {
        return Inertia::render('Project/New/SimpleDockerfile', [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'destinationUuid' => $destination->uuid,
            'serverId' => $server->id,
            'defaultDockerfile' => isDev() ? "FROM nginx\nEXPOSE 80\nCMD [\"nginx\", \"-g\", \"daemon off;\"]\n" : '',
            'submitUrl' => route('project.resource.create.dockerfile', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'destination' => $destination->uuid]),
        ]);
    }

    private function renderDockerImageForm(Project $project, Environment $environment, StandaloneDocker|SwarmDocker $destination, Server $server): Response
    {
        return Inertia::render('Project/New/DockerImage', [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'destinationUuid' => $destination->uuid,
            'serverId' => $server->id,
            'submitUrl' => route('project.resource.create.docker-image', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'destination' => $destination->uuid]),
        ]);
    }

    private function renderDockerComposeForm(Project $project, Environment $environment, StandaloneDocker|SwarmDocker $destination, Server $server): Response
    {
        return Inertia::render('Project/New/DockerCompose', [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
            'destinationUuid' => $destination->uuid,
            'serverId' => $server->id,
            'defaultDockerComposeRaw' => isDev() && file_exists(base_path('templates/test-database-detection.yaml'))
                ? file_get_contents(base_path('templates/test-database-detection.yaml'))
                : '',
            'submitUrl' => route('project.resource.create.docker-compose', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid, 'destination' => $destination->uuid]),
        ]);
    }
}
