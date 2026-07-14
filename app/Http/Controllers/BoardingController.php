<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proxy\CheckProxy;
use App\Actions\Proxy\StartProxy;
use App\Enums\ProxyTypes;
use App\Events\ServerValidated;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\ConfigurationRepository;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

/**
 * React port of App\Livewire\Boarding\Index — the first-run onboarding wizard. Its three nested
 * Livewire children (Server\New\ByHetzner, Server\ActivityMonitor, Server\ValidateAndInstall)
 * stay untouched: ByHetzner is still needed by Server\Create (reachable via Server\Show's
 * still-Livewire chrome), ValidateAndInstall is still needed by Server\Show itself. Hetzner Cloud
 * server creation is deliberately not offered here (explicit user decision, matching Phase 76's
 * "IP-only, accept the loss" precedent) — it remains reachable only via that same still-Livewire
 * chrome, not fully unreachable.
 *
 * The original has two overlapping SSH-validation engines wired together only through Livewire's
 * browser-event bus (Index's own inline validateServer()/continueValidation(), and the nested
 * ValidateAndInstall component's separate chain — the latter is the only one that actually calls
 * installDocker(), so they're not fully redundant, just entangled). validate() below collapses
 * both into one orchestrator built from the same underlying Server model/Action primitives
 * (validateConnection, validatePrerequisites/installPrerequisites, validateDockerEngine/
 * installDocker, validateDockerEngineVersion, gatherServerMetadata, CheckProxy, StartProxy) —
 * no new provisioning logic invented, just one call path instead of two.
 *
 * createServer()/createProject() are boarding-specific rather than reusing server.store/
 * project.store directly: both of those redirect to pages outside this wizard (server.show,
 * project.show) which would abandon onboarding mid-flow. They return JSON instead, mirroring the
 * original's saveServer()/createNewProject() logic exactly. security.private-key.store's
 * existing modal_mode flag already does the right thing (back() with flash data, no navigation
 * away), so the private-key step reuses PrivateKeyCreateModal.jsx/that route unchanged.
 */
class BoardingController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $localhostServer = Server::find(0);

        $privateKeys = PrivateKey::ownedAndOnlySShKeys(['id', 'name'])->where('id', '!=', 0)->get();
        $projects = Project::ownedByCurrentTeam()->get()->map(fn (Project $project) => [
            'uuid' => $project->uuid,
            'name' => $project->name,
            'environmentUuid' => $project->environments->first()?->uuid,
        ]);

        return Inertia::render('Boarding/Index', [
            'localhostServer' => $localhostServer ? [
                'uuid' => $localhostServer->uuid,
                'name' => $localhostServer->name,
            ] : null,
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => ['id' => $key->id, 'name' => $key->name]),
            'projects' => $projects,
            'minDockerVersion' => (string) str(config('constants.docker.minimum_required_version'))->before('.'),
            'createServerUrl' => route('onboarding.create-server'),
            'validateUrl' => route('onboarding.validate'),
            'createProjectUrl' => route('onboarding.create-project'),
            'skipUrl' => route('onboarding.skip'),
            'resourceCreateBaseUrl' => url('/project'),
            'privateKeyCreateUrl' => route('security.private-key.store'),
            'privateKeyGenerateUrl' => route('security.private-key.generate'),
        ]);
    }

    public function createServer(Request $request): JsonResponse
    {
        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
                'ip' => 'required|string',
                'port' => 'required|integer|between:1,65535',
                'user' => ValidationPatterns::serverUsernameRules(),
                'private_key_id' => 'required|integer',
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $this->authorize('create', Server::class);

        $foundServer = Server::whereIp($validated['ip'])->first();
        if ($foundServer) {
            $message = $foundServer->team_id === currentTeam()->id
                ? 'A server with this IP/Domain already exists in your team.'
                : 'A server with this IP/Domain is already in use by another team.';

            return response()->json(['message' => $message], 422);
        }

        $server = Server::create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'ip' => $validated['ip'],
            'port' => $validated['port'],
            'user' => $validated['user'],
            'private_key_id' => $validated['private_key_id'],
            'team_id' => currentTeam()->id,
        ]);

        return response()->json(['uuid' => $server->uuid, 'name' => $server->name]);
    }

    public function validateServer(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'server_uuid' => 'required|string',
            'install' => 'boolean',
            'attempt' => 'integer|min:0',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->where('uuid', $validated['server_uuid'])->firstOrFail();
        $this->authorize('update', $server);

        $install = $validated['install'] ?? true;
        $attempt = $validated['attempt'] ?? 0;
        $maxAttempts = 3;

        app(ConfigurationRepository::class)->disableSshMux();

        $connection = $server->validateConnection();
        if (! $connection['uptime']) {
            return response()->json(['status' => 'unreachable', 'error' => $connection['error']]);
        }

        if (! $server->validateOS()) {
            return response()->json(['status' => 'unsupported_os']);
        }

        $prerequisites = $server->validatePrerequisites();
        if (! $prerequisites['success']) {
            if (! $install) {
                $missing = implode(', ', $prerequisites['missing']);

                return response()->json(['status' => 'failed', 'error' => "Prerequisites ({$missing}) are not installed. Please install them before continuing."]);
            }
            if ($attempt >= $maxAttempts) {
                $missing = implode(', ', $prerequisites['missing']);

                return response()->json(['status' => 'failed', 'error' => "Prerequisites ({$missing}) could not be installed after {$maxAttempts} attempts. Please install them manually."]);
            }
            $activity = $server->installPrerequisites();

            return response()->json(['status' => 'installing', 'step' => 'prerequisites', 'activityId' => $activity->id, 'attempt' => $attempt + 1]);
        }

        $dockerReady = $server->validateDockerEngine() && $server->validateDockerCompose();
        if (! $dockerReady) {
            if (! $install) {
                return response()->json(['status' => 'failed', 'error' => 'Docker Engine is not installed. Please install Docker manually before continuing.']);
            }
            if ($attempt >= $maxAttempts) {
                return response()->json(['status' => 'failed', 'error' => 'Docker Engine could not be installed. Please install Docker manually before continuing.']);
            }
            $activity = $server->installDocker();

            return response()->json(['status' => 'installing', 'step' => 'docker', 'activityId' => $activity->id, 'attempt' => $attempt + 1]);
        }

        if ($server->isSwarm()) {
            try {
                $server->validateDockerSwarm();
            } catch (\Throwable $e) {
                return response()->json(['status' => 'failed', 'error' => $e->getMessage()]);
            }
        } elseif (! $server->validateDockerEngineVersion()) {
            $requiredVersion = str(config('constants.docker.minimum_required_version'))->before('.');

            return response()->json(['status' => 'failed', 'error' => "Minimum Docker Engine version {$requiredVersion} is not installed. Please install Docker manually before continuing."]);
        }

        $server->update(['is_validating' => false]);
        $server->gatherServerMetadata();

        ServerValidated::dispatch($server->team_id, $server->uuid);

        $server->proxy->type = ProxyTypes::TRAEFIK->value;
        $server->proxy->status = 'exited';
        $server->proxy->last_saved_settings = null;
        $server->proxy->last_applied_settings = null;
        $server->save();

        $proxyShouldRun = CheckProxy::run($server, true);
        if ($proxyShouldRun) {
            instant_remote_process(ensureProxyNetworksExist($server)->toArray(), $server, false);
            StartProxy::dispatch($server);
        }

        return response()->json(['status' => 'validated', 'serverUuid' => $server->uuid]);
    }

    public function createProject(): JsonResponse
    {
        $project = Project::create([
            'name' => 'My first project',
            'team_id' => currentTeam()->id,
            'uuid' => (string) new Cuid2,
        ]);

        return response()->json([
            'uuid' => $project->uuid,
            'name' => $project->name,
            'environmentUuid' => $project->environments->first()?->uuid,
        ]);
    }

    public function skip(): RedirectResponse
    {
        Team::find(currentTeam()->id)->update(['show_boarding' => false]);
        refreshSession();

        return redirect()->route('dashboard');
    }
}
