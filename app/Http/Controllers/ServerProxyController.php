<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Models\Server;
use App\Rules\SafeExternalUrl;
use App\Support\ServerChromeData;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class ServerProxyController extends Controller
{
    use AuthorizesRequests;

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $selectedProxy = $server->proxyType();
        $proxySettings = null;
        if ($selectedProxy && $selectedProxy !== 'NONE') {
            try {
                $proxySettings = GetProxyConfiguration::run($server);
            } catch (\Throwable) {
                $proxySettings = null;
            }
        }

        return Inertia::render('Server/Proxy', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'proxy', 'configuration'),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'selectedProxy' => $selectedProxy,
            'proxyStatus' => data_get($server, 'proxy.status'),
            'proxyOutOfSync' => (bool) (
                data_get($server, 'proxy.last_applied_settings')
                && data_get($server, 'proxy.last_saved_settings') !== data_get($server, 'proxy.last_applied_settings')
            ),
            'proxySettings' => $proxySettings,
            'configurationFilePath' => rtrim($server->proxyPath(), '/').'/docker-compose.yml',
            'generateExactLabels' => (bool) $server->settings->generate_exact_labels,
            'redirectEnabled' => (bool) data_get($server, 'proxy.redirect_enabled', true),
            'redirectUrl' => data_get($server, 'proxy.redirect_url'),
            'detectedTraefikVersion' => $server->detected_traefik_version,
            'latestTraefikVersion' => $this->latestTraefikVersion($server),
            'isTraefikOutdated' => $this->isTraefikOutdated($server),
            'newerTraefikBranchAvailable' => $this->newerTraefikBranchAvailable($server),
            'selectProxyUrl' => route('server.proxy.select', ['server_uuid' => $server->uuid]),
            'resetProxySelectionUrl' => route('server.proxy.reset-selection', ['server_uuid' => $server->uuid]),
            'instantSaveUrl' => route('server.proxy.instant-save', ['server_uuid' => $server->uuid]),
            'instantSaveRedirectUrl' => route('server.proxy.instant-save-redirect', ['server_uuid' => $server->uuid]),
            'submitUrl' => route('server.proxy.submit', ['server_uuid' => $server->uuid]),
            'resetConfigurationUrl' => route('server.proxy.reset-configuration', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function selectProxy(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'proxy_type' => ['required', 'string', 'in:NONE,TRAEFIK,CADDY'],
        ])->validate();

        try {
            $server->changeProxy($validated['proxy_type'], async: false);
        } catch (Exception $e) {
            return back()->with('error', $e->getMessage());
        }

        return back();
    }

    public function resetProxySelection(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $server->proxy = null;
        $server->save();

        return back();
    }

    public function instantSave(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'generateExactLabels' => ['required', 'boolean'],
        ])->validate();

        $server->settings->generate_exact_labels = $validated['generateExactLabels'];
        $server->settings->save();

        return back()->with('success', 'Settings saved.');
    }

    public function instantSaveRedirect(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'redirectEnabled' => ['required', 'boolean'],
        ])->validate();

        try {
            $server->proxy->redirect_enabled = $validated['redirectEnabled'];
            $server->save();
            $server->setupDefaultRedirect();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Proxy configuration saved.');
    }

    public function submit(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'proxySettings' => ['required', 'string'],
            'redirectUrl' => ['nullable', new SafeExternalUrl],
        ])->validate();

        try {
            SaveProxyConfiguration::run($server, $validated['proxySettings']);
            $server->proxy->redirect_url = $validated['redirectUrl'] ?? null;
            $server->save();
            $server->setupDefaultRedirect();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Proxy configuration saved.');
    }

    public function resetConfiguration(string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        try {
            $proxySettings = GetProxyConfiguration::run($server, forceRegenerate: true);
            SaveProxyConfiguration::run($server, $proxySettings);
            $server->save();
        } catch (\Throwable $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Proxy configuration reset to default.');
    }

    /**
     * @return array<string, string>|null
     */
    private function getTraefikVersions(Server $server): ?array
    {
        $versionsData = get_versions_data();
        if (! $versionsData) {
            return null;
        }

        $traefikVersions = data_get($versionsData, 'traefik');

        return is_array($traefikVersions) ? $traefikVersions : null;
    }

    private function latestTraefikVersion(Server $server): ?string
    {
        try {
            $traefikVersions = $this->getTraefikVersions($server);
            if (! $traefikVersions) {
                return null;
            }

            $currentVersion = $server->detected_traefik_version;
            if ($currentVersion && $currentVersion !== 'latest') {
                $current = ltrim($currentVersion, 'v');
                if (preg_match('/^(\d+\.\d+)/', $current, $matches)) {
                    $branch = "v{$matches[1]}";
                    if (isset($traefikVersions[$branch])) {
                        $version = $traefikVersions[$branch];

                        return str_starts_with($version, 'v') ? $version : "v{$version}";
                    }
                }
            }

            $newestVersion = collect($traefikVersions)
                ->map(fn ($v) => ltrim($v, 'v'))
                ->sortBy(fn ($v) => $v, SORT_NATURAL)
                ->last();

            return $newestVersion ? "v{$newestVersion}" : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function isTraefikOutdated(Server $server): bool
    {
        if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
            return false;
        }

        $currentVersion = $server->detected_traefik_version;
        if (! $currentVersion || $currentVersion === 'latest') {
            return false;
        }

        $latestVersion = $this->latestTraefikVersion($server);
        if (! $latestVersion) {
            return false;
        }

        $current = ltrim($currentVersion, 'v');
        $latest = ltrim($latestVersion, 'v');

        return version_compare($current, $latest, '<');
    }

    private function newerTraefikBranchAvailable(Server $server): ?string
    {
        try {
            if ($server->proxyType() !== ProxyTypes::TRAEFIK->value) {
                return null;
            }

            $currentVersion = $server->detected_traefik_version;
            if (! $currentVersion || $currentVersion === 'latest') {
                return null;
            }

            $outdatedInfo = $server->traefik_outdated_info;
            if ($outdatedInfo && $outdatedInfo['type'] === 'minor_upgrade') {
                if (isset($outdatedInfo['upgrade_target'])) {
                    return str_starts_with($outdatedInfo['upgrade_target'], 'v')
                        ? $outdatedInfo['upgrade_target']
                        : "v{$outdatedInfo['upgrade_target']}";
                }
            }

            $traefikVersions = $this->getTraefikVersions($server);
            if (! $traefikVersions) {
                return null;
            }

            $current = ltrim($currentVersion, 'v');
            if (! preg_match('/^(\d+\.\d+)/', $current, $matches)) {
                return null;
            }

            $currentBranch = $matches[1];

            $newestBranch = null;
            foreach ($traefikVersions as $branch => $version) {
                $branchNum = ltrim($branch, 'v');
                if (version_compare($branchNum, $currentBranch, '>')) {
                    if (! $newestBranch || version_compare($branchNum, $newestBranch, '>')) {
                        $newestBranch = $branchNum;
                    }
                }
            }

            return $newestBranch ? "v{$newestBranch}" : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
