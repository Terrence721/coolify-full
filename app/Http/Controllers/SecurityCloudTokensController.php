<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CloudProviderToken;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SecurityCloudTokensController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CloudProviderToken::class);

        $tokens = CloudProviderToken::ownedByCurrentTeam()->get();

        return Inertia::render('Security/CloudTokens', [
            'canCreate' => Gate::forUser($request->user())->allows('create', CloudProviderToken::class),
            'tokens' => $tokens->map(fn (CloudProviderToken $token) => [
                'id' => $token->id,
                'provider' => $token->provider,
                'name' => $token->name,
                'createdAgo' => $token->created_at->diffForHumans(),
                'validateUrl' => route('security.cloud-tokens.validate', ['id' => $token->id]),
                'destroyUrl' => route('security.cloud-tokens.destroy', ['id' => $token->id]),
            ]),
            'storeUrl' => route('security.cloud-tokens.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CloudProviderToken::class);

        $validated = Validator::make($request->all(), [
            'provider' => ['required', 'string', 'in:hetzner,digitalocean'],
            'token' => ['required', 'string'],
            'name' => ['required', 'string', 'max:255'],
        ], [
            'provider.required' => 'Please select a cloud provider.',
            'provider.in' => 'Invalid cloud provider selected.',
            'token.required' => 'API token is required.',
            'name.required' => 'Token name is required.',
        ])->validate();

        if (! $this->validateProviderToken($validated['provider'], $validated['token'])) {
            return back()->with('error', 'Invalid API token. Please check your token and try again.');
        }

        CloudProviderToken::create([
            'team_id' => currentTeam()->id,
            'provider' => $validated['provider'],
            'token' => $validated['token'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Cloud provider token added successfully.');
    }

    public function validateToken(int $id): RedirectResponse
    {
        $token = CloudProviderToken::ownedByCurrentTeam()->findOrFail($id);
        $this->authorize('view', $token);

        $isValid = match ($token->provider) {
            'hetzner' => $this->validateHetznerToken($token->token),
            'digitalocean' => $this->validateDigitalOceanToken($token->token),
            default => null,
        };

        if (is_null($isValid)) {
            return back()->with('error', 'Unknown provider.');
        }

        return $isValid
            ? back()->with('success', ucfirst($token->provider).' token is valid.')
            : back()->with('error', ucfirst($token->provider).' token validation failed. Please check the token.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $token = CloudProviderToken::ownedByCurrentTeam()->findOrFail($id);
        $this->authorize('delete', $token);

        if ($token->hasServers()) {
            $serverCount = $token->servers()->count();

            return back()->with('error', "Cannot delete this token. It is currently used by {$serverCount} server(s). Please reassign those servers to a different token first.");
        }

        $token->delete();

        return back()->with('success', 'Cloud provider token deleted successfully.');
    }

    private function validateProviderToken(string $provider, string $token): bool
    {
        try {
            if ($provider === 'hetzner') {
                $response = Http::withHeaders(['Authorization' => 'Bearer '.$token])
                    ->timeout(10)
                    ->get('https://api.hetzner.cloud/v1/servers');

                return $response->successful();
            }

            // Add other providers here in the future
            // if ($provider === 'digitalocean') { ... }

            return false;
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateProviderToken().', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function validateHetznerToken(string $token): bool
    {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://api.hetzner.cloud/v1/servers?per_page=1');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateHetznerToken().', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function validateDigitalOceanToken(string $token): bool
    {
        try {
            $response = Http::withToken($token)
                ->timeout(10)
                ->get('https://api.digitalocean.com/v2/account');

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in validateDigitalOceanToken().', ['error' => $e->getMessage()]);
            return false;
        }
    }
}
