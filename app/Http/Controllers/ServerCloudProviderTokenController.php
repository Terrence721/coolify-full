<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CloudProviderToken;
use App\Models\Server;
use App\Support\ServerChromeData;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerCloudProviderTokenController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        return Inertia::render('Server/CloudProviderToken', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'main', 'cloud-provider-token'),
            'hasHetznerServerId' => (bool) $server->hetzner_server_id,
            'currentTokenId' => data_get($server, 'cloudProviderToken.id'),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'canCreate' => auth()->user()?->can('create', CloudProviderToken::class) ?? false,
            'tokens' => CloudProviderToken::ownedByCurrentTeam()
                ->where('provider', 'hetzner')
                ->get()
                ->map(fn (CloudProviderToken $token) => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'createdAt' => $token->created_at->diffForHumans(),
                ]),
            'setTokenUrl' => route('server.cloud-provider-token.set', ['server_uuid' => $server->uuid]),
            'validateTokenUrl' => route('server.cloud-provider-token.validate', ['server_uuid' => $server->uuid]),
            'createTokenUrl' => route('server.cloud-provider-token.store', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function setToken(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $validated = Validator::make($request->all(), [
            'token_id' => ['required', 'integer'],
        ])->validate();

        $ownedToken = CloudProviderToken::ownedByCurrentTeam()->find($validated['token_id']);
        if (is_null($ownedToken)) {
            return back()->with('error', 'You are not allowed to use this token.');
        }

        $this->authorize('update', $server);

        try {
            $validation = $this->validateTokenForServer($ownedToken, $server);
            if (! $validation['valid']) {
                return back()->with('error', $validation['error']);
            }

            $server->cloudProviderToken()->associate($ownedToken);
            $server->save();
        } catch (Exception $e) {
            $server->refresh();

            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Hetzner token updated successfully.');
    }

    public function validateToken(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $token = $server->cloudProviderToken;
        if (! $token) {
            return back()->with('error', 'No Hetzner token is associated with this server.');
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            if ($response->successful()) {
                return back()->with('success', 'Hetzner token is valid and working.');
            }

            return back()->with('error', 'Hetzner token is invalid or has insufficient permissions.');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateToken().', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to validate token: '.$e->getMessage());
        }
    }

    public function store(Request $request, string $server_uuid): RedirectResponse
    {
        Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $this->authorize('create', CloudProviderToken::class);

        $validated = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'token' => ['required', 'string'],
        ], [
            'name.required' => 'Token name is required.',
            'token.required' => 'API token is required.',
        ])->validate();

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$validated['token'],
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            if (! $response->successful()) {
                return back()->with('error', 'Invalid API token. Please check your token and try again.');
            }

            CloudProviderToken::create([
                'team_id' => currentTeam()->id,
                'provider' => 'hetzner',
                'token' => $validated['token'],
                'name' => $validated['name'],
            ]);
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in store().', ['error' => $e->getMessage()]);
            return back()->with('error', 'Failed to validate token: '.$e->getMessage());
        }

        return back()->with('success', 'Cloud provider token added successfully.');
    }

    /**
     * @return array{valid: bool, error?: string}
     */
    private function validateTokenForServer(CloudProviderToken $token, Server $server): array
    {
        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer '.$token->token,
            ])->timeout(10)->get('https://api.hetzner.cloud/v1/servers');

            if (! $response->successful()) {
                return [
                    'valid' => false,
                    'error' => 'This token is invalid or has insufficient permissions.',
                ];
            }

            if ($server->hetzner_server_id) {
                $serverResponse = Http::withHeaders([
                    'Authorization' => 'Bearer '.$token->token,
                ])->timeout(10)->get("https://api.hetzner.cloud/v1/servers/{$server->hetzner_server_id}");

                if (! $serverResponse->successful()) {
                    return [
                        'valid' => false,
                        'error' => 'This token cannot access this server. It may belong to a different Hetzner project.',
                    ];
                }
            }

            return ['valid' => true];
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateTokenForServer().', ['error' => $e->getMessage()]);
            return [
                'valid' => false,
                'error' => 'Failed to validate token: '.$e->getMessage(),
            ];
        }
    }
}
