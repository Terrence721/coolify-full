<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class EnvironmentController extends Controller
{
    use AuthorizesRequests;

    public function edit(string $project_uuid, string $environment_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();

        return Inertia::render('Project/EnvironmentEdit', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
            ],
            'environment' => [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'description' => $environment->description,
                'isEmpty' => $environment->isEmpty(),
            ],
            'canUpdate' => auth()->user()?->can('update', $environment) ?? false,
            'canDelete' => auth()->user()?->can('delete', $environment) ?? false,
            'projectShowUrl' => route('project.show', ['project_uuid' => $project->uuid]),
            'resourceIndexUrl' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'updateUrl' => route('project.environment.update', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            'deleteUrl' => route('project.environment.destroy', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
        ]);
    }

    public function update(Request $request, string $project_uuid, string $environment_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('update', $environment);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $environment->update($validated);

        return redirect()->route('project.environment.edit', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
        ]);
    }

    public function destroy(string $project_uuid, string $environment_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $this->authorize('delete', $environment);

        if (! $environment->isEmpty()) {
            return back()->with('error', "Environment {$environment->name} has defined resources, please delete them first.");
        }

        $environment->delete();

        return redirect()->route('project.show', ['project_uuid' => $project->uuid]);
    }
}
