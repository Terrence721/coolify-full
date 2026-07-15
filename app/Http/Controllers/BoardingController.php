<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\ServerValidationService;
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
 * React port of the former App\Livewire\Boarding\Index — the first-run onboarding wizard. Only
 * IP-based server creation is offered here (explicit user decision, Phase 76). Server\New\ByHetzner
 * and the rest of the Livewire server-creation chain it depended on were deleted once
 * auth/verify-email.blade.php — their last remaining entry point — converted to React (Phase 79);
 * Hetzner Cloud server creation is consequently gone from the UI entirely unless rebuilt as a
 * React flow from scratch, a permanent, deliberately-accepted loss (see todo.md).
 *
 * Server\ActivityMonitor and Server\ValidateAndInstall, both formerly needed by Server\Show, were
 * deleted in Phase 78 once Show converted — validateServer() below (extracted into
 * ServerValidationService, shared with the new ServerShowController) replaced what
 * ValidateAndInstall's nested-component chain used to do. The original had two overlapping
 * SSH-validation engines wired together only through Livewire's browser-event bus (Index's own
 * inline validateServer()/continueValidation(), and ValidateAndInstall's separate chain — the
 * latter was the only one that actually called installDocker(), so they weren't fully redundant,
 * just entangled). validate() below collapses both into one orchestrator built from the same
 * underlying Server model/Action primitives (validateConnection, validatePrerequisites/
 * installPrerequisites, validateDockerEngine/installDocker, validateDockerEngineVersion,
 * gatherServerMetadata, CheckProxy, StartProxy) — no new provisioning logic invented, just one
 * call path instead of two.
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

    public function validateServer(Request $request, ServerValidationService $validationService): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'server_uuid' => 'required|string',
            'install' => 'boolean',
            'attempt' => 'integer|min:0',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->where('uuid', $validated['server_uuid'])->firstOrFail();
        $this->authorize('update', $server);

        return response()->json($validationService->validate(
            $server,
            $validated['install'] ?? true,
            $validated['attempt'] ?? 0,
        ));
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
