<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Database\StartDatabaseProxy;
use App\Actions\Database\StopDatabaseProxy;
use App\Http\Controllers\Concerns\ManagesDatabaseImport;
use App\Http\Controllers\Concerns\NormalizesServiceFqdns;
use App\Http\Controllers\Concerns\ResolvesProjectResources;
use App\Http\Controllers\Concerns\StreamsContainerLogs;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Url\Url;

/**
 * React port of App\Livewire\Project\Service\Index's `project.service.index` and
 * `project.service.index.advanced` routes — a service's per-application-or-database
 * general/advanced settings. `project.service.database.import` deliberately stays on the
 * original Livewire class (it nests App\Livewire\Project\Database\Import, itself still
 * needed by the still-Livewire Project\Database\Configuration — porting Import is its own
 * scope, left for whenever that page converts).
 */
class ProjectServiceResourceController extends Controller
{
    use AuthorizesRequests;
    use ManagesDatabaseImport;
    use NormalizesServiceFqdns;
    use ResolvesProjectResources;
    use StreamsContainerLogs;

    public function show(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): Response|RedirectResponse
    {
        return $this->renderResource($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid, 'general');
    }

    public function advanced(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): Response|RedirectResponse
    {
        return $this->renderResource($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid, 'advanced');
    }

    public function updateApplication(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceApplication] = $this->resolveApplicationOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceApplication);

        $validated = Validator::make($request->all(), [
            'human_name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'fqdn' => ['nullable', 'string'],
            'image' => ['required', 'string'],
            'force_save_domains' => ['boolean'],
            'force_remove_port' => ['boolean'],
        ])->validate();

        $fqdn = $this->normalizeFqdn((string) ($validated['fqdn'] ?? ''));
        $warning = $fqdn ? sslipDomainWarning($fqdn) : false;

        $serviceApplication->human_name = $validated['human_name'] ?? null;
        $serviceApplication->description = $validated['description'] ?? null;
        $serviceApplication->fqdn = $fqdn;
        $serviceApplication->image = $validated['image'];

        if (! $request->boolean('force_save_domains')) {
            $result = checkDomainUsage(resource: $serviceApplication);
            if ($result['hasConflicts']) {
                return back()->with(['domainConflicts' => $result['conflicts'], 'showDomainConflictModal' => true]);
            }
        }

        if (! $request->boolean('force_remove_port')) {
            $requiredPort = $serviceApplication->getRequiredPort();
            if ($requiredPort !== null && $this->fqdnsMissingPort($fqdn)) {
                return back()->with(['requiredPort' => $requiredPort, 'showPortWarningModal' => true]);
            }
        }

        $serviceApplication->save();
        updateCompose($serviceApplication);

        if (str($serviceApplication->fqdn)->contains(',')) {
            return back()->with('warning', 'Some services do not support multiple domains, which can lead to problems and is NOT RECOMMENDED. Only use multiple domains if you know what you are doing.');
        }
        if ($warning) {
            return back();
        }

        return back()->with('success', 'Service saved.');
    }

    public function updateApplicationAdvanced(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceApplication] = $this->resolveApplicationOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceApplication);

        $validated = Validator::make($request->all(), [
            'is_gzip_enabled' => ['required', 'boolean'],
            'is_stripprefix_enabled' => ['required', 'boolean'],
            'exclude_from_status' => ['required', 'boolean'],
            'is_log_drain_enabled' => ['required', 'boolean'],
        ])->validate();

        if ($validated['is_log_drain_enabled'] && ! $serviceApplication->service->destination->server->isLogDrainEnabled()) {
            return back()->with('error', 'Log drain is not enabled on the server. Please enable it first.');
        }

        $serviceApplication->update($validated);

        return back()->with('success', 'You need to restart the service for the changes to take effect.');
    }

    public function convertApplicationToDatabase(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [$service, $serviceApplication] = $this->resolveApplicationOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceApplication);

        if ($service->databases()->where('name', $serviceApplication->name)->exists()) {
            return back()->with('error', 'A database with this name already exists.');
        }

        DB::transaction(function () use ($service, $serviceApplication) {
            $service->databases()->create([
                'name' => $serviceApplication->name,
                'human_name' => $serviceApplication->human_name,
                'description' => $serviceApplication->description,
                'exclude_from_status' => $serviceApplication->exclude_from_status,
                'is_log_drain_enabled' => $serviceApplication->is_log_drain_enabled,
                'image' => $serviceApplication->image,
                'service_id' => $service->id,
                'is_migrated' => true,
            ]);
            $serviceApplication->delete();
        });

        return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'));
    }

    public function deleteApplication(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceApplication] = $this->resolveApplicationOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('delete', $serviceApplication);

        $serviceApplication->delete();

        return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'))
            ->with('success', 'Application deleted.');
    }

    public function updateDatabase(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceDatabase);

        $validated = Validator::make($request->all(), [
            'human_name' => ['nullable', 'string'],
            'description' => ['nullable', 'string'],
            'image' => ['required', 'string'],
        ])->validate();

        $serviceDatabase->update($validated);
        updateCompose($serviceDatabase);

        return back()->with('success', 'Database saved.');
    }

    public function updateDatabaseAdvanced(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceDatabase);

        $validated = Validator::make($request->all(), [
            'exclude_from_status' => ['required', 'boolean'],
            'is_log_drain_enabled' => ['required', 'boolean'],
        ])->validate();

        if ($validated['is_log_drain_enabled'] && ! $serviceDatabase->service->destination->server->isLogDrainEnabled()) {
            return back()->with('error', 'Log drain is not enabled on the server. Please enable it first.');
        }

        $serviceDatabase->update($validated);

        return back()->with('success', 'You need to restart the service for the changes to take effect.');
    }

    public function updateDatabasePublic(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceDatabase);

        $validated = Validator::make($request->all(), [
            'is_public' => ['required', 'boolean'],
            'public_port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'public_port_timeout' => ['nullable', 'integer', 'min:1'],
        ])->validate();

        if ($validated['is_public'] && empty($validated['public_port'] ?? null)) {
            return back()->with('error', 'Public port is required.');
        }

        $serviceDatabase->public_port = $validated['public_port'] ?? null;
        $serviceDatabase->public_port_timeout = $validated['public_port_timeout'] ?? null;

        if ($validated['is_public']) {
            if (! str($serviceDatabase->status)->startsWith('running')) {
                return back()->with('error', 'Database must be started to be publicly accessible.');
            }
            $serviceDatabase->is_public = true;
            $serviceDatabase->save();
            StartDatabaseProxy::run($serviceDatabase);

            return back()->with('success', 'Database is now publicly accessible.');
        }

        $serviceDatabase->is_public = false;
        $serviceDatabase->save();
        StopDatabaseProxy::run($serviceDatabase);

        return back()->with('success', 'Database is no longer publicly accessible.');
    }

    public function convertDatabaseToApplication(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [$service, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('update', $serviceDatabase);

        if ($service->applications()->where('name', $serviceDatabase->name)->exists()) {
            return back()->with('error', 'An application with this name already exists.');
        }

        DB::transaction(function () use ($service, $serviceDatabase) {
            $service->applications()->create([
                'name' => $serviceDatabase->name,
                'human_name' => $serviceDatabase->human_name,
                'description' => $serviceDatabase->description,
                'exclude_from_status' => $serviceDatabase->exclude_from_status,
                'is_log_drain_enabled' => $serviceDatabase->is_log_drain_enabled,
                'image' => $serviceDatabase->image,
                'service_id' => $service->id,
                'is_migrated' => true,
            ]);
            $serviceDatabase->delete();
        });

        return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'));
    }

    public function deleteDatabase(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('delete', $serviceDatabase);

        $serviceDatabase->delete();

        return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'))
            ->with('success', 'Database deleted.');
    }

    public function proxyLogs(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): JsonResponse
    {
        [$service, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);
        $this->authorize('view', $serviceDatabase);

        $server = $service->server;
        $numberOfLines = max(1, min(50000, (int) $request->query('lines', 100)));
        $showTimestamps = $request->query('timestamps', '1') !== '0';

        $logLines = [];
        if ($server && $server->isFunctional()) {
            $rawOutput = $this->fetchContainerLogs($server, "{$serviceDatabase->uuid}-proxy", $numberOfLines, $showTimestamps);
            $logLines = $this->parseContainerLogLines($rawOutput, $server);
        }

        return response()->json(['logLines' => $logLines]);
    }

    public function import(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): Response|RedirectResponse
    {
        return $this->renderResource($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid, 'import');
    }

    public function importCheckFileEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);

        return $this->importCheckFile($request, $serviceDatabase);
    }

    public function importRunEndpoint(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);

        return $this->importRun($request, $serviceDatabase);
    }

    public function importCheckS3Endpoint(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);

        return $this->importCheckS3($request, $serviceDatabase);
    }

    public function importRestoreS3Endpoint(Request $request, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): RedirectResponse
    {
        [, $serviceDatabase] = $this->resolveDatabaseOr404($project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid);

        return $this->importRestoreS3($request, $serviceDatabase);
    }

    private function renderResource(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, string $tab): Response|RedirectResponse
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        if ($service instanceof RedirectResponse) {
            return $service;
        }

        $this->authorize('view', $service);

        $serviceApplication = $service->applications()->whereUuid($stack_service_uuid)->first();
        if ($serviceApplication) {
            return $this->renderApplication($service, $serviceApplication, $project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid, $tab);
        }

        $serviceDatabase = $service->databases()->whereUuid($stack_service_uuid)->first();
        if (! $serviceDatabase) {
            return redirect()->route('project.service.configuration', compact('project_uuid', 'environment_uuid', 'service_uuid'));
        }

        return $this->renderDatabase($service, $serviceDatabase, $project_uuid, $environment_uuid, $service_uuid, $stack_service_uuid, $tab);
    }

    private function renderApplication(Service $service, ServiceApplication $serviceApplication, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, string $tab): Response
    {
        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid', 'stack_service_uuid');
        $serviceParameters = compact('project_uuid', 'environment_uuid', 'service_uuid');
        $requiredPort = $serviceApplication->getRequiredPort();
        $isKnownServiceType = (bool) $serviceApplication->serviceType()?->contains(str($serviceApplication->image)->before(':'));

        return Inertia::render('Project/Service/Resource', [
            'resourceType' => 'application',
            'tab' => $tab,
            'service' => $this->serviceHeadingProps($service),
            'serviceHeadingUrls' => $this->serviceHeadingUrls($serviceParameters),
            'parameters' => $parameters,
            'serviceParameters' => $serviceParameters,
            'application' => [
                'uuid' => $serviceApplication->uuid,
                'name' => $serviceApplication->name,
                'humanName' => $serviceApplication->human_name,
                'description' => $serviceApplication->description,
                'fqdn' => $serviceApplication->fqdn,
                'image' => $serviceApplication->image,
                'requiredFqdn' => (bool) $serviceApplication->required_fqdn,
                'requiredPort' => $requiredPort,
                'isKnownServiceType' => $isKnownServiceType,
                'isGzipToggleDisabled' => str($serviceApplication->image)->contains('pocketbase'),
                'isGzipEnabled' => (bool) $serviceApplication->is_gzip_enabled,
                'isStripprefixEnabled' => (bool) $serviceApplication->is_stripprefix_enabled,
                'excludeFromStatus' => (bool) $serviceApplication->exclude_from_status,
                'isLogDrainEnabled' => (bool) $serviceApplication->is_log_drain_enabled,
            ],
            'urls' => [
                'update' => route('project.service.application.update', $parameters),
                'updateAdvanced' => route('project.service.application.update-advanced', $parameters),
                'convert' => route('project.service.application.convert', $parameters),
                'delete' => route('project.service.application.delete', $parameters),
            ],
        ]);
    }

    private function renderDatabase(Service $service, ServiceDatabase $serviceDatabase, string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid, string $tab): Response
    {
        $parameters = compact('project_uuid', 'environment_uuid', 'service_uuid', 'stack_service_uuid');
        $serviceParameters = compact('project_uuid', 'environment_uuid', 'service_uuid');
        $dbType = $serviceDatabase->databaseType();
        $supportedTypes = ['mysql', 'mariadb', 'postgres', 'mongo'];
        $isImportSupported = collect($supportedTypes)->contains(fn (string $type) => str_contains($dbType, $type));

        return Inertia::render('Project/Service/Resource', [
            'resourceType' => 'database',
            'tab' => $tab,
            'service' => $this->serviceHeadingProps($service),
            'serviceHeadingUrls' => $this->serviceHeadingUrls($serviceParameters),
            'parameters' => $parameters,
            'serviceParameters' => $serviceParameters,
            'database' => [
                'uuid' => $serviceDatabase->uuid,
                'name' => $serviceDatabase->name,
                'humanName' => $serviceDatabase->human_name,
                'description' => $serviceDatabase->description,
                'image' => $serviceDatabase->image,
                'isPublic' => (bool) $serviceDatabase->is_public,
                'publicPort' => $serviceDatabase->public_port,
                'publicPortTimeout' => $serviceDatabase->public_port_timeout,
                'dbUrlPublic' => $serviceDatabase->is_public ? $serviceDatabase->getServiceDatabaseUrl() : null,
                'excludeFromStatus' => (bool) $serviceDatabase->exclude_from_status,
                'isLogDrainEnabled' => (bool) $serviceDatabase->is_log_drain_enabled,
                'isImportSupported' => $isImportSupported,
                'isBackupSolutionAvailable' => (bool) $serviceDatabase->isBackupSolutionAvailable(),
                'isMigrated' => (bool) $serviceDatabase->is_migrated,
            ],
            ...($tab === 'import' ? $this->importTabProps($serviceDatabase, 'project.service.database', $parameters) : []),
            'urls' => [
                'update' => route('project.service.database.update', $parameters),
                'updateAdvanced' => route('project.service.database.update-advanced', $parameters),
                'updatePublic' => route('project.service.database.update-public', $parameters),
                'convert' => route('project.service.database.convert', $parameters),
                'delete' => route('project.service.database.delete', $parameters),
                'proxyLogs' => route('project.service.database.proxy-logs', $parameters),
                'backups' => route('project.service.database.backups', $parameters),
                'import' => route('project.service.database.import', $parameters),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serviceHeadingProps(Service $service): array
    {
        return [
            'uuid' => $service->uuid,
            'name' => $service->name,
            'status' => $service->status,
            'isDeployable' => $service->isDeployable,
        ];
    }

    /**
     * @param  array<string, string>  $serviceParameters
     * @return array<string, mixed>
     */
    private function serviceHeadingUrls(array $serviceParameters): array
    {
        return [
            'start' => route('project.logs.service.start', $serviceParameters),
            'forceDeploy' => route('project.logs.service.force-deploy', $serviceParameters),
            'restart' => route('project.logs.service.restart', $serviceParameters),
            'stop' => route('project.logs.service.stop', $serviceParameters),
            'checkStatus' => route('project.logs.service.check-status', $serviceParameters),
        ];
    }

    /**
     * @return array{0: Service, 1: ServiceApplication}
     */
    private function resolveApplicationOr404(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): array
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        abort_if($service instanceof RedirectResponse, 404);
        $serviceApplication = $service->applications()->whereUuid($stack_service_uuid)->first();
        abort_if(! $serviceApplication, 404);

        return [$service, $serviceApplication];
    }

    /**
     * @return array{0: Service, 1: ServiceDatabase}
     */
    private function resolveDatabaseOr404(string $project_uuid, string $environment_uuid, string $service_uuid, string $stack_service_uuid): array
    {
        $service = $this->resolveService($project_uuid, $environment_uuid, $service_uuid);
        abort_if($service instanceof RedirectResponse, 404);
        $serviceDatabase = $service->databases()->whereUuid($stack_service_uuid)->first();
        abort_if(! $serviceDatabase, 404);

        return [$service, $serviceDatabase];
    }

}
