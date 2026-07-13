<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Rules\ValidGitBranch;
use App\Rules\ValidGitRepositoryUrl;
use App\Support\ValidationPatterns;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Url\Url;

/**
 * React port of the 3 GitHub-dependent creation flows deferred by Phase 51's
 * ProjectResourceCreateController: App\Livewire\Project\New\{PublicGitRepository,
 * GithubPrivateRepository, GithubPrivateRepositoryDeployKey} plus the thin
 * App\Livewire\Project\Resource\GitCreate shell that hosted them.
 *
 * Pre-existing bugs fixed during the port (catalogued in Phase 51's notes):
 * - De Morgan error in the `.git`-suffix normalization: `(!contains(github.com) ||
 *   !contains(git.sr.ht))` is always true, so `.git` was appended to the very hosts the
 *   condition meant to exclude. Now `.git` is only appended when the URL matches none of them.
 * - Unbounded pagination loops: repository paging never terminated when GitHub returned an
 *   empty page while still reporting a larger total_count, and branch paging never terminated
 *   when a later page kept failing (the failure path didn't update the loop counter). Both
 *   loops now break on an empty/failed page and carry a hard page cap.
 * - `GithubApp::where('name', 'Public GitHub')->first()` had no null guard before `->id` /
 *   `->getMorphClass()` access; a missing row now falls back to the sourceless "other" path.
 * - `base_directory` reached Application::create with no validation rule in the two private
 *   flows (and `docker_compose_location` in none of the wire:model.defer paths); all three
 *   store endpoints now validate both via ValidationPatterns.
 * - The deploy-key flow accepted any client-supplied `private_key_id`; it is now resolved
 *   through the current team (404 otherwise), matching what the picker actually lists.
 *
 * Deliberately not ported (dead code in the original): the `new_compose_services` isDev-only
 * branch of PublicGitRepository::submit() (no UI control ever set it) and the dead
 * `wire:target="loadRepositories"` spinner in the deploy-key blade (references a method that
 * doesn't exist on that component). The "+ Add GitHub App" modal is the shared React
 * GithubAppCreateModal (Phase 53), embedded in the private-gh-app page.
 *
 * GitHub-API branch checking is stateless here, unlike the Livewire original whose
 * `rate_limit_remaining` persisted between requests and made the main->master fallback
 * unreachable on a fresh component (any first failure hit the assume-found path). The port
 * always tries the master fallback for 'main', then assumes the branch exists — deployment
 * surfaces a wrong branch later, exactly as before.
 */
class ProjectResourceGitCreateController extends Controller
{
    private const GIT_TYPES = ['public', 'private-gh-app', 'private-deploy-key'];

    private const BUILD_PACKS = ['nixpacks', 'railpack', 'static', 'dockerfile', 'dockercompose'];

    private const OWNER_PATTERN = '/^[a-zA-Z0-9\-_]+$/';

    private const REPO_PATTERN = '/^[a-zA-Z0-9\-_\.]+$/';

    private const MAX_PAGES = 100;

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

        $type = (string) $request->query('type');
        if (! in_array($type, self::GIT_TYPES, true)) {
            return redirect()->route('project.resource.create', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        }

        $routeParams = ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid];
        $destinationUuid = (string) $request->query('destination');
        $common = [
            'project' => ['uuid' => $project->uuid, 'name' => $project->name],
            'environment' => ['uuid' => $environment->uuid, 'name' => $environment->name],
        ];

        return match ($type) {
            'public' => Inertia::render('Project/New/PublicGitRepository', [
                ...$common,
                'defaultRepositoryUrl' => isDev() ? 'https://github.com/coollabsio/coolify-examples/tree/v4.x' : '',
                'checkUrl' => route('project.resource.create.git.check', $routeParams),
                'submitUrl' => route('project.resource.create.git.public', [...$routeParams, 'destination' => $destinationUuid]),
            ]),
            'private-gh-app' => Inertia::render('Project/New/GithubPrivateRepository', [
                ...$common,
                'githubApps' => GithubApp::ownedByCurrentTeam()
                    ->where('is_public', false)
                    ->whereNotNull('app_id')
                    ->get()
                    ->map(fn (GithubApp $app) => ['id' => $app->id, 'name' => $app->name, 'htmlUrl' => $app->html_url])
                    ->values(),
                'githubAppStoreUrl' => route('source.github.store'),
                'githubAppDefaultName' => substr(generate_random_name(), 0, 30),
                'isCloud' => isCloud(),
                'repositoriesUrl' => route('project.resource.create.git.repositories', $routeParams),
                'branchesUrl' => route('project.resource.create.git.branches', $routeParams),
                'submitUrl' => route('project.resource.create.git.private-gh-app', [...$routeParams, 'destination' => $destinationUuid]),
            ]),
            'private-deploy-key' => Inertia::render('Project/New/GithubPrivateRepositoryDeployKey', [
                ...$common,
                'defaultRepositoryUrl' => isDev() ? 'https://github.com/coollabsio/coolify-examples/tree/v4.x' : '',
                'privateKeys' => PrivateKey::where('team_id', currentTeam()->id)
                    ->when(! isDev(), fn ($query) => $query->where('id', '!=', 0))
                    ->get()
                    ->map(fn (PrivateKey $key) => ['id' => $key->id, 'name' => $key->name, 'description' => $key->description])
                    ->values(),
                'privateKeyIndexUrl' => route('security.private-key.index'),
                'submitUrl' => route('project.resource.create.git.private-deploy-key', [...$routeParams, 'destination' => $destinationUuid]),
            ]),
        };
    }

    /**
     * The "Check repository" step of the public flow — normalizes the URL, resolves the git
     * source, and (for github.com with a "Public GitHub" source) verifies the branch against
     * the GitHub API, walking slashed branch names down to their base branch + subpath.
     */
    public function checkPublicRepository(Request $request, string $project_uuid, string $environment_uuid): JsonResponse
    {
        $this->resolveProjectEnvironment($project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'repository_url' => ['required', 'string', new ValidGitRepositoryUrl],
        ])->validate();

        $url = $this->normalizePublicRepositoryUrl($validated['repository_url']);
        [$repository, $branch, $source] = $this->resolvePublicGitSource($url);

        $baseDirectory = '/';
        $branchFound = false;
        $rateLimitRemaining = null;
        $rateLimitReset = null;

        if (! $source) {
            $branchFound = true;
        } else {
            $originalBranch = $branch;
            $branchToTry = $branch;
            try {
                while (true) {
                    try {
                        $encodedBranch = urlencode($branchToTry);
                        ['rate_limit_remaining' => $rateLimitRemaining, 'rate_limit_reset' => $rateLimitReset] = githubApi(source: $source, endpoint: "/repos/{$repository}/branches/{$encodedBranch}");
                        $rateLimitReset = Carbon::parse((int) $rateLimitReset)->format('Y-M-d H:i:s');
                        $branch = $branchToTry;

                        $remaining = str($originalBranch)->after($branchToTry)->trim('/')->value();
                        $baseDirectory = filled($remaining) ? '/'.$remaining : '/';

                        $branchFound = true;
                        break;
                    } catch (\Throwable $e) {
                        if (str_contains($branchToTry, '/')) {
                            $branchToTry = str($branchToTry)->beforeLast('/')->value();

                            continue;
                        }

                        throw $e;
                    }
                }
            } catch (\Throwable) {
                if ($branch === 'main') {
                    try {
                        githubApi(source: $source, endpoint: "/repos/{$repository}/branches/master");
                        $branch = 'master';
                    } catch (\Throwable) {
                        // Assume the guessed branch exists; a wrong guess surfaces at deploy time.
                    }
                }
                $branchFound = true;
            }
        }

        if (str($url)->contains('tangled')) {
            $branch = 'master';
        }

        return response()->json([
            'repositoryUrl' => $url,
            'branch' => $branch,
            'branchFound' => $branchFound,
            'baseDirectory' => $baseDirectory,
            'isGithub' => $source !== null,
            'rateLimitRemaining' => $rateLimitRemaining,
            'rateLimitReset' => $rateLimitReset,
        ]);
    }

    public function loadRepositories(Request $request, string $project_uuid, string $environment_uuid): JsonResponse
    {
        $this->resolveProjectEnvironment($project_uuid, $environment_uuid);
        $githubApp = $this->resolveGithubApp((int) $request->query('github_app_id'));

        try {
            $token = generateGithubInstallationToken($githubApp);
        } catch (\Throwable $e) {
            return response()->json(['message' => strip_tags($e->getMessage())], 422);
        }

        $page = 1;
        $result = loadRepositoryByPage($githubApp, $token, $page);
        $totalCount = $result['total_count'];
        $repositories = collect($result['repositories']);

        while ($repositories->count() < $totalCount && $page < self::MAX_PAGES) {
            $page++;
            $result = loadRepositoryByPage($githubApp, $token, $page);
            if (count($result['repositories']) === 0) {
                break;
            }
            $totalCount = $result['total_count'];
            $repositories = $repositories->concat($result['repositories']);
        }

        return response()->json([
            'repositories' => $repositories->sortBy('name')->values()->map(fn ($repository) => [
                'id' => data_get($repository, 'id'),
                'name' => data_get($repository, 'name'),
                'owner' => data_get($repository, 'owner.login'),
            ])->values(),
            'totalCount' => $totalCount,
            'installationUrl' => getInstallationPath($githubApp),
        ]);
    }

    public function loadBranches(Request $request, string $project_uuid, string $environment_uuid): JsonResponse
    {
        $this->resolveProjectEnvironment($project_uuid, $environment_uuid);

        $validated = Validator::make($request->query(), [
            'github_app_id' => ['required', 'integer'],
            'owner' => ['required', 'string', 'regex:'.self::OWNER_PATTERN],
            'repo' => ['required', 'string', 'regex:'.self::REPO_PATTERN],
        ])->validate();

        $githubApp = $this->resolveGithubApp((int) $validated['github_app_id']);

        try {
            $token = generateGithubInstallationToken($githubApp);
        } catch (\Throwable $e) {
            return response()->json(['message' => strip_tags($e->getMessage())], 422);
        }

        $branches = collect();
        $page = 1;
        do {
            $response = Http::GitHub($githubApp->api_url, $token)
                ->timeout(20)
                ->retry(3, 200, throw: false)
                ->get("/repos/{$validated['owner']}/{$validated['repo']}/branches", [
                    'per_page' => 100,
                    'page' => $page,
                ]);
            if ($response->status() !== 200) {
                if ($branches->isEmpty()) {
                    return response()->json(['message' => data_get($response->json(), 'message', 'Failed to load branches.')], 422);
                }
                break;
            }
            $json = $response->json();
            $branches = $branches->concat($json);
            $page++;
        } while (count($json) === 100 && $page <= self::MAX_PAGES);

        return response()->json([
            'branches' => sortBranchesByPriority($branches)->map(fn ($branch) => ['name' => data_get($branch, 'name')])->values(),
        ]);
    }

    public function storePublic(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'repository_url' => ['required', 'string', new ValidGitRepositoryUrl],
            'git_branch' => ['required', 'string', new ValidGitBranch],
            'port' => ['required', 'numeric'],
            'is_static' => ['required', 'boolean'],
            'publish_directory' => ['nullable', 'string'],
            'build_pack' => ['required', 'string', Rule::in(self::BUILD_PACKS)],
            'base_directory' => ValidationPatterns::directoryPathRules(),
            'docker_compose_location' => ValidationPatterns::filePathRules(),
        ])->validate();

        $url = $this->normalizePublicRepositoryUrl($validated['repository_url']);
        [$repository, , $source] = $this->resolvePublicGitSource($url);

        $applicationInit = [
            'name' => $source
                ? generate_application_name($repository, $validated['git_branch'])
                : generate_random_name(),
            'git_repository' => $repository,
            'git_branch' => $validated['git_branch'],
            'ports_exposes' => $validated['port'],
            'publish_directory' => $validated['publish_directory'] ?? null,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'build_pack' => $validated['build_pack'],
            'base_directory' => $validated['base_directory'] ?? '/',
        ];
        if ($source) {
            $applicationInit['source_id'] = $source->id;
            $applicationInit['source_type'] = $source->getMorphClass();
        }
        if ($validated['build_pack'] === 'dockerfile') {
            $applicationInit['health_check_enabled'] = false;
        }
        if ($validated['build_pack'] === 'dockercompose') {
            $applicationInit['docker_compose_location'] = $validated['docker_compose_location'] ?? '/docker-compose.yaml';
        }

        $application = Application::create($applicationInit);
        $application->settings->is_static = $validated['is_static'];
        $application->settings->save();
        $application->fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->save();

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function storePrivateGithubApp(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'github_app_id' => ['required', 'integer'],
            'repository_id' => ['required', 'integer'],
            'owner' => ['required', 'string', 'regex:'.self::OWNER_PATTERN],
            'repo' => ['required', 'string', 'regex:'.self::REPO_PATTERN],
            'git_branch' => ['required', 'string', new ValidGitBranch],
            'port' => ['required', 'numeric'],
            'is_static' => ['required', 'boolean'],
            'publish_directory' => ['nullable', 'string'],
            'build_pack' => ['required', 'string', Rule::in(self::BUILD_PACKS)],
            'base_directory' => ValidationPatterns::directoryPathRules(),
            'docker_compose_location' => ValidationPatterns::filePathRules(),
        ])->validate();

        $githubApp = $this->resolveGithubApp((int) $validated['github_app_id']);
        $gitRepository = str($validated['owner'])->trim()->value().'/'.str($validated['repo'])->trim()->value();

        $application = Application::create([
            'name' => generate_application_name($gitRepository, $validated['git_branch']),
            'repository_project_id' => $validated['repository_id'],
            'git_repository' => $gitRepository,
            'git_branch' => str($validated['git_branch'])->trim()->value(),
            'build_pack' => $validated['build_pack'],
            'ports_exposes' => $validated['port'],
            'publish_directory' => $validated['publish_directory'] ?? null,
            'base_directory' => $validated['base_directory'] ?? '/',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'source_id' => $githubApp->id,
            'source_type' => $githubApp->getMorphClass(),
        ]);
        $application->settings->is_static = $validated['is_static'];
        $application->settings->save();

        if ($validated['build_pack'] === 'dockerfile') {
            $application->health_check_enabled = false;
        }
        if ($validated['build_pack'] === 'dockercompose') {
            $application->docker_compose_location = $validated['docker_compose_location'] ?? '/docker-compose.yaml';
        }
        $application->fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->name = generate_application_name($gitRepository, $validated['git_branch'], $application->uuid);
        $application->save();

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    public function storePrivateDeployKey(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        [$project, $environment, $destination] = $this->resolveDestinationForSubmit($request, $project_uuid, $environment_uuid);

        $validated = Validator::make($request->all(), [
            'private_key_id' => ['required', 'integer'],
            'repository_url' => ['required', 'string', new ValidGitRepositoryUrl],
            'git_branch' => ['required', 'string', new ValidGitBranch],
            'port' => ['required', 'numeric'],
            'is_static' => ['required', 'boolean'],
            'publish_directory' => ['nullable', 'string'],
            'build_pack' => ['required', 'string', Rule::in(self::BUILD_PACKS)],
            'base_directory' => ValidationPatterns::directoryPathRules(),
            'docker_compose_location' => ValidationPatterns::filePathRules(),
        ])->validate();

        $privateKey = PrivateKey::where('team_id', currentTeam()->id)
            ->when(! isDev(), fn ($query) => $query->where('id', '!=', 0))
            ->findOrFail($validated['private_key_id']);

        [$gitRepository, $source] = $this->resolveDeployKeyGitSource($validated['repository_url']);

        $applicationInit = [
            'name' => generate_random_name(),
            'git_repository' => $gitRepository,
            'git_branch' => $validated['git_branch'],
            'build_pack' => $validated['build_pack'],
            'ports_exposes' => $validated['port'],
            'publish_directory' => $validated['publish_directory'] ?? null,
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'private_key_id' => $privateKey->id,
        ];
        if ($source) {
            $applicationInit['source_id'] = $source->id;
            $applicationInit['source_type'] = $source->getMorphClass();
        }
        if ($validated['build_pack'] === 'dockerfile') {
            $applicationInit['health_check_enabled'] = false;
        }
        if ($validated['build_pack'] === 'dockercompose') {
            $applicationInit['docker_compose_location'] = $validated['docker_compose_location'] ?? '/docker-compose.yaml';
            $applicationInit['base_directory'] = $validated['base_directory'] ?? '/';
        }

        $application = Application::create($applicationInit);
        $application->settings->is_static = $validated['is_static'];
        $application->settings->save();
        $application->fqdn = generateUrl(server: $destination->server, random: $application->uuid);
        $application->name = generate_random_name($application->uuid);
        $application->save();

        return redirect()->route('project.application.configuration', [
            'application_uuid' => $application->uuid,
            'environment_uuid' => $environment->uuid,
            'project_uuid' => $project->uuid,
        ]);
    }

    /**
     * git@ SSH URLs become https, `.git` is appended for hosts that expect it (fixed De Morgan
     * condition: none of github.com / git.sr.ht / tangled), and github.com URLs are stripped
     * of a trailing `.git` — same net behavior the original's loadBranch() intended.
     */
    private function normalizePublicRepositoryUrl(string $url): string
    {
        if (str($url)->startsWith('git@')) {
            $host = str($url)->after('git@')->before(':');
            $repository = str($url)->after(':')->before('.git');
            $url = 'https://'.$host.'/'.$repository;
        }
        if (
            (str($url)->startsWith('https://') || str($url)->startsWith('http://'))
            && ! str($url)->endsWith('.git')
            && ! str($url)->contains('github.com')
            && ! str($url)->contains('git.sr.ht')
            && ! str($url)->contains('tangled')
        ) {
            $url .= '.git';
        }
        if (str($url)->contains('github.com') && str($url)->endsWith('.git')) {
            $url = str($url)->beforeLast('.git')->value();
        }

        return $url;
    }

    /**
     * @return array{0: string, 1: string, 2: GithubApp|null} [repository, branch, source]
     */
    private function resolvePublicGitSource(string $url): array
    {
        $branch = 'main';
        $parsed = Url::fromString($url);
        $host = $parsed->getHost();
        $repository = $parsed->getSegment(1).'/'.$parsed->getSegment(2);

        if ($parsed->getSegment(3) === 'tree') {
            $branch = str($parsed->getPath())->trim('/')->after('tree/')->value();
        }

        $source = $host === 'github.com' ? GithubApp::where('name', 'Public GitHub')->first() : null;
        if (! $source) {
            $repository = $url;
        }

        return [$repository, $branch, $source];
    }

    /**
     * @return array{0: string, 1: GithubApp|null} [git_repository, source]
     */
    private function resolveDeployKeyGitSource(string $url): array
    {
        $parsed = Url::fromString($url);
        $host = $parsed->getHost();
        $repository = $parsed->getSegment(1).'/'.$parsed->getSegment(2);

        $source = $host === 'github.com' ? GithubApp::where('name', 'Public GitHub')->first() : null;
        if ($source) {
            return [$repository, $source];
        }

        if (str($url)->startsWith('http')) {
            // Convert to SSH format for deploy key usage
            $repository = Str::finish("git@{$host}:{$repository}", '.git');
        } else {
            // Already in SSH format, use as-is
            $repository = $url;
        }

        return [$repository, null];
    }

    private function resolveGithubApp(int $githubAppId): GithubApp
    {
        return GithubApp::ownedByCurrentTeam()
            ->where('is_public', false)
            ->whereNotNull('app_id')
            ->findOrFail($githubAppId);
    }

    /**
     * @return array{0: Project, 1: Environment}
     */
    private function resolveProjectEnvironment(string $project_uuid, string $environment_uuid): array
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        return [$project, $environment];
    }

    /**
     * @return array{0: Project, 1: Environment, 2: StandaloneDocker|SwarmDocker}
     */
    private function resolveDestinationForSubmit(Request $request, string $project_uuid, string $environment_uuid): array
    {
        [$project, $environment] = $this->resolveProjectEnvironment($project_uuid, $environment_uuid);

        $destination = find_destination_for_current_team($request->query('destination'));
        if (! $destination) {
            abort(404);
        }

        return [$project, $environment, $destination];
    }
}
