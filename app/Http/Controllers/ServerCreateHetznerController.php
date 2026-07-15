<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Server\CreateHetznerServer;
use App\Exceptions\RateLimitException;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\PrivateKey;
use App\Rules\ValidCloudInitYaml;
use App\Rules\ValidHostname;
use App\Services\HetznerService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

/**
 * New React flow for Hetzner Cloud server creation, rebuilt from scratch after the last
 * Livewire path (GlobalSearch → Server\Create → ByHetzner) was deleted in the migration's
 * closing phase. The Hetzner API integration itself (HetznerService, CreateHetznerServer
 * action) was never removed — only the UI wizard was gone, so this is a UI-only rebuild.
 */
class ServerCreateHetznerController extends Controller
{
    use AuthorizesRequests;

    public function index(): Response
    {
        $this->authorize('createAnyResource');

        $tokens = CloudProviderToken::ownedByCurrentTeam()->where('provider', 'hetzner')->get();
        $privateKeys = PrivateKey::ownedByCurrentTeamCached();
        $cloudInitScripts = CloudInitScript::ownedByCurrentTeam()->get();

        return Inertia::render('Server/New/Hetzner', [
            'tokens' => $tokens->map(fn (CloudProviderToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
            ]),
            'privateKeys' => $privateKeys->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
            ]),
            'cloudInitScripts' => $cloudInitScripts->map(fn (CloudInitScript $script) => [
                'id' => $script->id,
                'name' => $script->name,
                'script' => $script->script,
            ]),
            'defaultName' => generate_random_name(),
            'urls' => [
                'data' => route('server.new.hetzner.data'),
                'store' => route('server.new.hetzner.store'),
                'tokenStore' => route('security.cloud-tokens.store'),
                'privateKeyStore' => route('security.private-key.store'),
                'privateKeyGenerate' => route('security.private-key.generate'),
            ],
        ]);
    }

    public function data(Request $request): JsonResponse
    {
        $this->authorize('createAnyResource');

        $validated = Validator::make($request->all(), [
            'token_id' => 'required|integer',
        ])->validate();

        $token = CloudProviderToken::ownedByCurrentTeam()->where('provider', 'hetzner')->find($validated['token_id']);
        if (! $token) {
            return response()->json(['message' => 'Invalid token selected.'], 422);
        }

        try {
            $hetznerService = new HetznerService($token->token);

            return response()->json([
                'locations' => $hetznerService->getLocations(),
                'serverTypes' => $hetznerService->getServerTypes(),
                'images' => $hetznerService->getImages(),
                'sshKeys' => $hetznerService->getSshKeys(),
            ]);
        } catch (RateLimitException $e) {
            $response = response()->json(['message' => $e->getMessage()], 429);
            if ($e->retryAfter !== null) {
                $response->header('Retry-After', (string) $e->retryAfter);
            }

            return $response;
        } catch (\Throwable $e) {
            return response()->json(['message' => 'Failed to fetch Hetzner Cloud data: '.$e->getMessage()], 422);
        }
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('createAnyResource');

        $validated = Validator::make($request->all(), [
            'token_id' => 'required|integer',
            'private_key_id' => 'required|integer',
            'location' => 'required|string',
            'server_type' => 'required|string',
            'image' => 'required|integer',
            'name' => ['nullable', 'string', 'max:253', new ValidHostname],
            'enable_ipv4' => 'required|boolean',
            'enable_ipv6' => 'required|boolean',
            'hetzner_ssh_key_ids' => 'nullable|array',
            'hetzner_ssh_key_ids.*' => 'integer',
            'cloud_init_script' => ['nullable', 'string', new ValidCloudInitYaml],
            'save_cloud_init_script' => 'nullable|boolean',
            'cloud_init_script_name' => 'nullable|required_if:save_cloud_init_script,true|string|max:255',
            'instant_validate' => 'nullable|boolean',
        ])->validate();

        $token = CloudProviderToken::ownedByCurrentTeam()->where('provider', 'hetzner')->find($validated['token_id']);
        if (! $token) {
            return back()->with('error', 'Invalid token selected.');
        }

        $privateKey = PrivateKey::ownedByCurrentTeam()->find($validated['private_key_id']);
        if (! $privateKey) {
            return back()->with('error', 'Invalid private key selected.');
        }

        if (! empty($validated['save_cloud_init_script']) && ! empty($validated['cloud_init_script'])) {
            CloudInitScript::create([
                'team_id' => currentTeam()->id,
                'name' => $validated['cloud_init_script_name'],
                'script' => $validated['cloud_init_script'],
            ]);
        }

        try {
            $server = CreateHetznerServer::run(
                token: $token,
                privateKey: $privateKey,
                teamId: currentTeam()->id,
                location: $validated['location'],
                serverType: $validated['server_type'],
                image: $validated['image'],
                name: $validated['name'] ?? generate_random_name(),
                enableIpv4: $validated['enable_ipv4'],
                enableIpv6: $validated['enable_ipv6'],
                hetznerSshKeyIds: $validated['hetzner_ssh_key_ids'] ?? [],
                cloudInitScript: $validated['cloud_init_script'] ?? null,
                instantValidate: $validated['instant_validate'] ?? false,
            );
        } catch (RateLimitException $e) {
            return back()->with('error', $e->getMessage());
        } catch (\Throwable $e) {
            return back()->with('error', 'Failed to create Hetzner server: '.$e->getMessage());
        }

        return redirect()->route('server.show', ['server_uuid' => $server->uuid])
            ->with('success', 'Hetzner Cloud server is being created...');
    }
}
