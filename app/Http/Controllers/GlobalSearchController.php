<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * JSON endpoints behind the React GlobalSearchModal.jsx — a parallel port of
 * App\Livewire\GlobalSearch, which stays alive unchanged for Boarding\Index/Server\Show, the two
 * pages still rendered through layouts/app.blade.php. Both sides share their actual search/
 * creatable-item logic via GlobalSearchService rather than duplicating it.
 */
class GlobalSearchController extends Controller
{
    public function data(GlobalSearchService $service): JsonResponse
    {
        $user = auth()->user();
        $team = currentTeam();
        $services = $service->loadServices($user);

        return response()->json([
            'searchableItems' => $service->loadSearchableItems($team),
            'creatableItems' => $service->loadCreatableItems($user, $services),
            'createUrls' => [
                'project' => route('project.store'),
                'team' => route('team.store'),
                'storage' => route('storage.store'),
                'privateKey' => route('security.private-key.store'),
                'privateKeyGenerate' => route('security.private-key.generate'),
            ],
        ]);
    }

    public function serverCreateData(): JsonResponse
    {
        $privateKeys = PrivateKey::ownedByCurrentTeamCached();

        $limitReached = false;
        if (isCloud()) {
            $limitReached = Team::serverLimitReached();
        }

        return response()->json([
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
            ]),
            'defaultPrivateKeyId' => $privateKeys->first()?->id,
            'defaultName' => generate_random_name(),
            'limitReached' => $limitReached,
            'storeUrl' => route('server.store'),
        ]);
    }

    public function servers(): JsonResponse
    {
        $servers = Server::isUsable()->get()->sortBy('name');

        return response()->json([
            'servers' => $servers->map(fn (Server $s) => [
                'id' => $s->id,
                'name' => $s->name,
                'description' => $s->description,
            ])->values(),
        ]);
    }

    public function destinations(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'server_id' => 'required|integer',
        ])->validate();

        $server = Server::ownedByCurrentTeam()->find($validated['server_id']);

        if (! $server) {
            return response()->json(['message' => 'Server not found'], 404);
        }

        $destinations = $server->destinations();

        if ($destinations->isEmpty()) {
            return response()->json(['message' => 'No destinations found on this server'], 404);
        }

        return response()->json([
            'destinations' => $destinations->map(fn ($d) => [
                'uuid' => $d->uuid,
                'name' => $d->name,
                'network' => $d->network ?? 'default',
            ])->values(),
        ]);
    }

    public function projects(): JsonResponse
    {
        $projects = Project::where('team_id', currentTeam()->id)->get();

        if ($projects->isEmpty()) {
            return response()->json(['message' => 'Please create a project first'], 404);
        }

        return response()->json([
            'projects' => $projects->map(fn (Project $p) => [
                'uuid' => $p->uuid,
                'name' => $p->name,
                'description' => $p->description,
            ])->values(),
        ]);
    }

    public function environments(Request $request): JsonResponse
    {
        $validated = Validator::make($request->all(), [
            'project_uuid' => 'required|string',
        ])->validate();

        $project = Project::ownedByCurrentTeam()->where('uuid', $validated['project_uuid'])->first();

        if (! $project) {
            return response()->json(['message' => 'Project not found'], 404);
        }

        $environments = $project->environments;

        if ($environments->isEmpty()) {
            return response()->json(['message' => 'No environments found in project'], 404);
        }

        return response()->json([
            'environments' => $environments->map(fn ($e) => [
                'uuid' => $e->uuid,
                'name' => $e->name,
                'description' => $e->description,
            ])->values(),
        ]);
    }
}
