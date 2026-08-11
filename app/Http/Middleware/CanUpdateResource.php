<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Support\DatabaseEngineRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

class CanUpdateResource
{
    public function handle(Request $request, Closure $next): Response
    {
        // Get resource from route parameters
        $resource = null;
        if ($request->route('application_uuid')) {
            $resource = Application::where('uuid', $request->route('application_uuid'))->first();
        } elseif ($request->route('stack_service_uuid')) {
            // Checked before service_uuid: routes carrying stack_service_uuid are nested under
            // service/{service_uuid}, so both params are present and the more specific resource
            // (the ServiceApplication/ServiceDatabase itself, not its parent Service) must win.
            $stack_service_uuid = $request->route('stack_service_uuid');
            $resource = ServiceApplication::where('uuid', $stack_service_uuid)->first() ??
                       ServiceDatabase::where('uuid', $stack_service_uuid)->first();
        } elseif ($request->route('service_uuid')) {
            $resource = Service::where('uuid', $request->route('service_uuid'))->first();
        } elseif ($request->route('database_uuid')) {
            $database_uuid = $request->route('database_uuid');
            foreach (DatabaseEngineRegistry::modelClasses() as $modelClass) {
                $resource = $modelClass::where('uuid', $database_uuid)->first();
                if ($resource) {
                    break;
                }
            }
        } elseif ($request->route('server_uuid')) {
            // For server routes, check if user can manage servers
            if (! auth()->user()->isAdmin()) {
                abort(403, 'You do not have permission to access this resource.');
            }

            return $next($request);
        } elseif ($request->route('environment_uuid')) {
            $resource = Environment::where('uuid', $request->route('environment_uuid'))->first();
        } elseif ($request->route('project_uuid')) {
            $resource = Project::ownedByCurrentTeam()->where('uuid', $request->route('project_uuid'))->first();
        }

        if (! $resource) {
            abort(404, 'Resource not found.');
        }

        if (! Gate::allows('update', $resource)) {
            abort(403, 'You do not have permission to update this resource.');
        }

        return $next($request);
    }
}
