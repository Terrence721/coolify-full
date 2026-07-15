<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Proxy\GetProxyConfiguration;
use App\Actions\Proxy\SaveProxyConfiguration;
use App\Enums\ProxyTypes;
use App\Http\Controllers\Concerns\StreamsContainerLogs;
use App\Models\Server;
use App\Rules\SafeExternalUrl;
use App\Rules\ValidProxyConfigFilename;
use App\Support\ServerChromeData;
use Exception;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\Yaml\Yaml;

class ServerProxyController extends Controller
{
    use AuthorizesRequests;
    use StreamsContainerLogs;

    private const PROXY_CONTAINER = 'coolify-proxy';

    public function index(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $selectedProxy = $server->proxyType();
        $proxySettings = null;
        if ($selectedProxy && $selectedProxy !== 'NONE') {
            try {
                $proxySettings = GetProxyConfiguration::run($server);
            } catch (\Throwable $e) {
                Log::error('Unhandled exception in index().', ['error' => $e->getMessage()]);
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

    public function logs(Request $request, string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $numberOfLines = max(1, min(50000, (int) $request->query('lines', 100)));
        $showTimestamps = $request->query('timestamps', '1') !== '0';

        $logLines = [];
        if ($server->isFunctional()) {
            $rawOutput = $this->fetchContainerLogs($server, self::PROXY_CONTAINER, $numberOfLines, $showTimestamps);
            $logLines = $this->parseContainerLogLines($rawOutput, $server);
        }

        return Inertia::render('Server/Proxy/Logs', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'proxy', 'logs'),
            'isFunctional' => $server->isFunctional(),
            'displayName' => 'Coolify Proxy',
            'logLines' => $logLines,
            'numberOfLines' => $numberOfLines,
            'showTimestamps' => $showTimestamps,
            'urls' => [
                'downloadAll' => route('server.proxy.logs.download', ['server_uuid' => $server->uuid, 'timestamps' => $showTimestamps ? 1 : 0]),
            ],
        ]);
    }

    public function downloadLogs(Request $request, string $server_uuid): HttpResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        if (! $server->isFunctional()) {
            abort(404);
        }
        $showTimestamps = $request->query('timestamps', '1') !== '0';

        return $this->downloadContainerLogsResponse($server, self::PROXY_CONTAINER, $showTimestamps, 'proxy');
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
            Log::error('Unhandled exception in instantSaveRedirect().', ['error' => $e->getMessage()]);
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
            Log::error('Unhandled exception in submit().', ['error' => $e->getMessage()]);
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
            Log::error('Unhandled exception in resetConfiguration().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Proxy configuration reset to default.');
    }

    public function dynamicConfigurations(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();

        $contents = [];
        if ($server->isFunctional()) {
            $contents = $this->loadDynamicConfigurations($server);
        }

        return Inertia::render('Server/Proxy/DynamicConfigurations', [
            'serverNavbar' => ServerChromeData::navbar($server),
            'sidebar' => ServerChromeData::sidebar($server, 'proxy', 'dynamicConfs'),
            'isFunctional' => $server->isFunctional(),
            'canUpdate' => auth()->user()?->can('update', $server) ?? false,
            'contents' => $contents,
            'storeUrl' => route('server.proxy.dynamic-confs.store', ['server_uuid' => $server->uuid]),
            'deleteUrl' => route('server.proxy.dynamic-confs.destroy', ['server_uuid' => $server->uuid]),
        ]);
    }

    public function storeDynamicConfiguration(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'fileName' => ['required', new ValidProxyConfigFilename],
            'value' => 'required|string',
            'newFile' => 'boolean',
        ])->validate();

        try {
            $fileName = $validated['fileName'];
            validateFilenameSafe($fileName, 'proxy configuration filename');

            $proxy_type = $server->proxyType();
            if ($proxy_type === ProxyTypes::TRAEFIK->value) {
                if (! str($fileName)->endsWith('.yaml') && ! str($fileName)->endsWith('.yml')) {
                    $fileName = "{$fileName}.yaml";
                }
                if ($fileName === 'coolify.yaml') {
                    return back()->with('error', 'File name is reserved.');
                }
            } elseif ($proxy_type === 'CADDY') {
                if (! str($fileName)->endsWith('.caddy')) {
                    $fileName = "{$fileName}.caddy";
                }
            }

            $proxy_path = $server->proxyPath();
            $file = "{$proxy_path}/dynamic/{$fileName}";
            $escapedFile = escapeshellarg($file);

            if ($validated['newFile'] ?? false) {
                $exists = instant_remote_process(["test -f {$escapedFile} && echo 1 || echo 0"], $server);
                if ($exists == 1) {
                    return back()->with('error', 'File already exists');
                }
            }

            $value = $validated['value'];
            if ($proxy_type === ProxyTypes::TRAEFIK->value) {
                $yaml = Yaml::parse($value);
                $value = Yaml::dump($yaml, 10, 2);
            }

            $base64_value = base64_encode($value);
            instant_remote_process([
                "echo '{$base64_value}' | base64 -d | tee {$escapedFile} > /dev/null",
            ], $server);
            if ($proxy_type === 'CADDY') {
                $server->reloadCaddy();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in storeDynamicConfiguration().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Dynamic configuration saved.');
    }

    public function destroyDynamicConfiguration(Request $request, string $server_uuid): RedirectResponse
    {
        $server = Server::ownedByCurrentTeam()->whereUuid($server_uuid)->firstOrFail();
        $this->authorize('update', $server);

        $validated = Validator::make($request->all(), [
            'fileName' => 'required|string',
        ])->validate();

        try {
            $file = $validated['fileName'];
            validateFilenameSafe($file, 'proxy configuration filename');

            $proxy_type = $server->proxyType();
            if ($proxy_type === 'CADDY' && $file === 'Caddyfile') {
                return back()->with('error', 'Cannot delete Caddyfile.');
            }

            $proxy_path = $server->proxyPath();
            $fullPath = "{$proxy_path}/dynamic/{$file}";
            $escapedPath = escapeshellarg($fullPath);
            instant_remote_process(["rm -f {$escapedPath}"], $server);
            if ($proxy_type === 'CADDY') {
                $server->reloadCaddy();
            }
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in destroyDynamicConfiguration().', ['error' => $e->getMessage()]);
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'File deleted.');
    }

    /**
     * @return array<int, array{fileName: string, value: string}>
     */
    private function loadDynamicConfigurations(Server $server): array
    {
        $proxy_path = $server->proxyPath();
        $files = instant_remote_process(["mkdir -p $proxy_path/dynamic && ls -1 {$proxy_path}/dynamic"], $server);
        $files = collect(explode("\n", $files))->filter(fn ($file) => ! empty($file))->map(fn ($file) => trim($file))->sort();

        $contents = [];
        foreach ($files as $file) {
            $content = instant_remote_process(["cat {$proxy_path}/dynamic/{$file}"], $server);
            $contents[] = ['fileName' => $file, 'value' => $content ?? ''];
        }

        return $contents;
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
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in latestTraefikVersion().', ['error' => $e->getMessage()]);
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
        } catch (\Throwable $e) {
            Log::error('Unhandled exception in newerTraefikBranchAvailable().', ['error' => $e->getMessage()]);
            return null;
        }
    }
}
