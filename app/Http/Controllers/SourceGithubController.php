<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\GithubAppPermissionJob;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Rules\SafeExternalUrl;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class SourceGithubController extends Controller
{
    use AuthorizesRequests;

    /**
     * React port of the `/sources` listing (previously a route closure rendering
     * `source.all` Blade). Team::sources() also returns GitlabApps, but the original view
     * only ever rendered GithubApp entries, so only those are mapped.
     */
    public function index(): Response
    {
        $sources = currentTeam()->sources()
            ->filter(fn ($source) => $source instanceof GithubApp)
            ->map(fn (GithubApp $githubApp) => [
                'uuid' => $githubApp->uuid,
                'name' => $githubApp->name,
                'organization' => $githubApp->organization,
                'configured' => ! is_null($githubApp->app_id),
                'url' => route('source.github.show', ['github_app_uuid' => $githubApp->uuid]),
            ])->values();

        return Inertia::render('Sources/Index', [
            'sources' => $sources,
            'canCreate' => auth()->user()->can('createAnyResource'),
            'storeUrl' => route('source.github.store'),
            'defaultName' => substr(generate_random_name(), 0, 30),
            'isCloud' => isCloud(),
        ]);
    }

    /**
     * React port of App\Livewire\Source\Github\Create::createGitHubApp().
     */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('createAnyResource');

        $validated = $request->validate([
            'name' => 'required|string',
            'organization' => 'nullable|string',
            'apiUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'htmlUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'customUser' => 'required|string',
            'customPort' => 'required|int',
            'isSystemWide' => 'required|bool',
        ]);

        $githubApp = GithubApp::create([
            'name' => $validated['name'],
            'organization' => $validated['organization'] ?? null,
            'api_url' => $validated['apiUrl'],
            'html_url' => $validated['htmlUrl'],
            'custom_user' => $validated['customUser'],
            'custom_port' => $validated['customPort'],
            'is_system_wide' => $validated['isSystemWide'],
            'team_id' => currentTeam()->id,
        ]);

        return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid]);
    }

    public function show(string $github_app_uuid): Response|RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $githubApp->makeVisible(['client_secret', 'webhook_secret']);

        $settings = instanceSettings();

        $ipv4 = $settings->public_ipv4 ? 'http://'.$settings->public_ipv4.':'.config('app.port') : null;
        $ipv6 = $settings->public_ipv6 ? 'http://'.$settings->public_ipv6.':'.config('app.port') : null;

        if (isCloud() && ! isDev()) {
            $webhookEndpoint = config('app.url');
        } else {
            $webhookEndpoint = $settings->fqdn ?? $ipv4 ?? $ipv6 ?? config('app.url') ?? '';
        }

        $routeName = request()->route()?->getName();
        $activeTab = match ($routeName) {
            'source.github.permissions' => 'permissions',
            'source.github.resources' => 'resources',
            default => 'general',
        };

        $applications = $githubApp->applications()->get();

        return Inertia::render('Source/Github/Change', [
            'githubApp' => [
                'uuid' => $githubApp->uuid,
                'name' => Str::kebab($githubApp->name),
                'organization' => $githubApp->organization,
                'apiUrl' => $githubApp->api_url,
                'htmlUrl' => $githubApp->html_url,
                'customUser' => $githubApp->custom_user,
                'customPort' => $githubApp->custom_port,
                'appId' => $githubApp->app_id,
                'installationId' => $githubApp->installation_id,
                'clientId' => $githubApp->client_id,
                'clientSecret' => $githubApp->client_secret,
                'webhookSecret' => $githubApp->webhook_secret,
                'isSystemWide' => $githubApp->is_system_wide,
                'privateKeyId' => $githubApp->private_key_id,
                'contents' => $githubApp->contents,
                'metadata' => $githubApp->metadata,
                'pullRequests' => $githubApp->pull_requests,
            ],
            'activeTab' => $activeTab,
            'isCloud' => isCloud(),
            'isDev' => isDev(),
            'fqdn' => $settings->fqdn,
            'ipv4' => $ipv4,
            'ipv6' => $ipv6,
            'appUrl' => config('app.url'),
            'webhookEndpoint' => $webhookEndpoint,
            'manifestState' => $this->createGithubAppSetupState($githubApp, 'manifest'),
            'devWebhookUrl' => config('constants.webhooks.dev_webhook'),
            'privateKeys' => PrivateKey::ownedByCurrentTeamCached()->map(fn (PrivateKey $key) => [
                'id' => $key->id,
                'name' => $key->name,
            ]),
            'applications' => $applications->sortBy('name', SORT_NATURAL)->values()->map(fn (Application $app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'projectName' => (string) data_get($app->project(), 'name'),
                'environmentName' => (string) data_get($app, 'environment.name'),
                'type' => (string) str($app->type())->headline(),
                'link' => $app->link(),
            ]),
            'canUpdate' => auth()->user()?->can('update', $githubApp) ?? false,
            'canDelete' => auth()->user()?->can('delete', $githubApp) ?? false,
            'canCreate' => auth()->user()?->can('create', GithubApp::class) ?? false,
            'installationPath' => getInstallationPath($githubApp),
            'permissionsPath' => getPermissionsPath($githubApp),
            'nameUpdatePath' => $this->githubAppNameUpdatePath($githubApp),
            'showUrl' => route('source.github.show', ['github_app_uuid' => $githubApp->uuid]),
            'permissionsUrl' => route('source.github.permissions', ['github_app_uuid' => $githubApp->uuid]),
            'resourcesUrl' => route('source.github.resources', ['github_app_uuid' => $githubApp->uuid]),
            'updateUrl' => route('source.github.update', ['github_app_uuid' => $githubApp->uuid]),
            'updateNameUrl' => route('source.github.update-name', ['github_app_uuid' => $githubApp->uuid]),
            'checkPermissionsUrl' => route('source.github.check-permissions', ['github_app_uuid' => $githubApp->uuid]),
            'instantSaveUrl' => route('source.github.instant-save', ['github_app_uuid' => $githubApp->uuid]),
            'createManualUrl' => route('source.github.create-manual', ['github_app_uuid' => $githubApp->uuid]),
            'deleteUrl' => route('source.github.destroy', ['github_app_uuid' => $githubApp->uuid]),
        ]);
    }

    public function update(Request $request, string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('update', $githubApp);
        $githubApp->makeVisible('client_secret')->makeVisible('webhook_secret');

        $validated = $request->validate([
            'name' => 'required|string',
            'organization' => 'nullable|string',
            'apiUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'htmlUrl' => ['required', 'string', 'url', new SafeExternalUrl],
            'customUser' => 'required|string',
            'customPort' => 'required|int',
            'appId' => 'nullable|int',
            'installationId' => 'nullable|int',
            'clientId' => 'nullable|string',
            'clientSecret' => 'nullable|string',
            'webhookSecret' => 'nullable|string',
            'isSystemWide' => 'required|bool',
            'privateKeyId' => 'nullable|int',
        ]);

        $githubApp->update([
            'name' => $validated['name'],
            'organization' => $validated['organization'] ?? null,
            'api_url' => $validated['apiUrl'],
            'html_url' => $validated['htmlUrl'],
            'custom_user' => $validated['customUser'],
            'custom_port' => $validated['customPort'],
            'app_id' => $validated['appId'] ?? null,
            'installation_id' => $validated['installationId'] ?? null,
            'client_id' => $validated['clientId'] ?? null,
            'client_secret' => $validated['clientSecret'] ?? null,
            'webhook_secret' => $validated['webhookSecret'] ?? null,
            'is_system_wide' => $validated['isSystemWide'],
            'private_key_id' => $validated['privateKeyId'] ?? null,
        ]);

        return back()->with('success', 'Github App updated.');
    }

    public function updateName(string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('update', $githubApp);

        $privateKey = PrivateKey::ownedByCurrentTeam()->find($githubApp->private_key_id);
        if (! $privateKey) {
            return back()->with('error', 'No private key found for this GitHub App.');
        }

        try {
            $jwt = $this->generateGithubJwt($privateKey->private_key, $githubApp->app_id);

            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'Authorization' => "Bearer {$jwt}",
            ])->get("{$githubApp->api_url}/app");

            if ($response->successful()) {
                $appSlug = $response->json()['slug'] ?? null;
                if ($appSlug) {
                    $githubApp->name = $appSlug;
                    $githubApp->save();
                    $privateKey->name = "github-app-{$appSlug}";
                    $privateKey->save();

                    return back()->with('success', 'GitHub App name and SSH key name synchronized successfully.');
                }

                return back()->with('info', 'Could not find App Name (slug) in GitHub response.');
            }

            $errorMessage = $response->json()['message'] ?? 'Unknown error';

            return back()->with('error', "Failed to fetch GitHub App information: {$errorMessage}");
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in updateName().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }
    }

    public function checkPermissions(string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('view', $githubApp);

        $missingFields = [];
        if (! $githubApp->app_id) {
            $missingFields[] = 'App ID';
        }
        if (! $githubApp->private_key_id) {
            $missingFields[] = 'Private Key';
        }
        if (! empty($missingFields)) {
            return back()->with('error', 'Cannot fetch permissions. Please set the following required fields first: '.implode(', ', $missingFields));
        }

        if (! $githubApp->privateKey()->first()) {
            return back()->with('error', 'Private Key not found. Please select a valid private key.');
        }

        try {
            GithubAppPermissionJob::dispatchSync($githubApp);

            return back()->with('success', 'Github App permissions updated.');
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in checkPermissions().', ['error' => $e->getMessage()]);
            $errorMessage = $e->getMessage();
            if (str_contains($errorMessage, 'DECODER routines::unsupported') || str_contains($errorMessage, 'parse your key')) {
                return back()->with('error', 'The selected private key format is not supported for GitHub Apps. Please use an RSA private key in PEM format (BEGIN RSA PRIVATE KEY). OpenSSH format keys (BEGIN OPENSSH PRIVATE KEY) are not supported.');
            }

            return back()->with('error', $errorMessage);
        }
    }

    public function instantSaveSystemWide(Request $request, string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('update', $githubApp);

        $validated = $request->validate([
            'isSystemWide' => 'required|bool',
        ]);

        $githubApp->is_system_wide = $validated['isSystemWide'];
        $githubApp->save();

        return back()->with('success', 'Github App updated.');
    }

    public function createManual(string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('update', $githubApp);

        $githubApp->makeVisible('client_secret')->makeVisible('webhook_secret');
        $githubApp->app_id = 1234567890;
        $githubApp->installation_id = 1234567890;
        $githubApp->save();

        return redirect()->route('source.github.show', ['github_app_uuid' => $githubApp->uuid])
            ->with('success', 'Github App updated. You can now configure the details.');
    }

    public function destroy(string $github_app_uuid): RedirectResponse
    {
        $githubApp = GithubApp::ownedByCurrentTeam()->whereUuid($github_app_uuid)->firstOrFail();
        $this->authorize('delete', $githubApp);

        if ($githubApp->applications()->exists()) {
            return back()->with('error', 'This source is being used by an application. Please delete all applications first.');
        }

        $githubApp->delete();

        return redirect()->route('source.all');
    }

    private function githubAppSetupStateCacheKey(string $state): string
    {
        return 'github-app-setup-state:'.hash('sha256', $state);
    }

    private function createGithubAppSetupState(GithubApp $githubApp, string $action): string
    {
        $state = Str::random(64);

        Cache::put($this->githubAppSetupStateCacheKey($state), [
            'action' => $action,
            'github_app_id' => $githubApp->id,
            'team_id' => $githubApp->team_id,
        ], now()->addMinutes(60));

        return $state;
    }

    private function githubAppNameUpdatePath(GithubApp $githubApp): string
    {
        if (str($githubApp->organization)->isNotEmpty()) {
            return "{$githubApp->html_url}/organizations/{$githubApp->organization}/settings/apps/{$githubApp->name}";
        }

        return "{$githubApp->html_url}/settings/apps/{$githubApp->name}";
    }

    private function generateGithubJwt(string $privateKey, int $appId): string
    {
        $configuration = Configuration::forAsymmetricSigner(
            new Sha256,
            InMemory::plainText($privateKey),
            InMemory::plainText($privateKey)
        );

        $now = time();

        return $configuration->builder()
            ->issuedBy((string) $appId)
            ->permittedFor('https://api.github.com')
            ->identifiedBy((string) $now)
            ->issuedAt(new \DateTimeImmutable("@{$now}"))
            ->expiresAt(new \DateTimeImmutable('@'.($now + 600)))
            ->getToken($configuration->signer(), $configuration->signingKey())
            ->toString();
    }
}
