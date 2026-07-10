<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Project;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Visus\Cuid2\Cuid2;

class ProjectController extends Controller
{
    use AuthorizesRequests;

    public function show(string $project_uuid): Response
    {
        $project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();

        return Inertia::render('Project/Show', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'isEmpty' => $project->isEmpty(),
            ],
            'environments' => $project->environments->sortBy('created_at')->values()->map(fn (Environment $environment) => [
                'uuid' => $environment->uuid,
                'name' => $environment->name,
                'description' => $environment->description,
                'showUrl' => route('project.resource.index', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
                'editUrl' => route('project.environment.edit', ['project_uuid' => $project->uuid, 'environment_uuid' => $environment->uuid]),
            ]),
            'canUpdate' => auth()->user()?->can('update', $project) ?? false,
            'canDelete' => auth()->user()?->can('delete', $project) ?? false,
            'createEnvironmentUrl' => route('project.create-environment', ['project_uuid' => $project->uuid]),
            'deleteUrl' => route('project.destroy', ['project_uuid' => $project->uuid]),
        ]);
    }

    public function createEnvironment(Request $request, string $project_uuid): RedirectResponse
    {
        $project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('update', $project);

        $validated = Validator::make(
            $request->all(),
            ['name' => ValidationPatterns::nameRules()],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $environment = Environment::create([
            'name' => $validated['name'],
            'project_id' => $project->id,
            'uuid' => (string) new Cuid2,
        ]);

        return redirect()->route('project.resource.index', [
            'project_uuid' => $project->uuid,
            'environment_uuid' => $environment->uuid,
        ]);
    }

    public function edit(string $project_uuid): Response
    {
        $project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();

        return Inertia::render('Project/Edit', [
            'project' => [
                'uuid' => $project->uuid,
                'name' => $project->name,
                'description' => $project->description,
                'isEmpty' => $project->isEmpty(),
            ],
            'canDelete' => auth()->user()?->can('delete', $project) ?? false,
            'updateUrl' => route('project.update', ['project_uuid' => $project->uuid]),
            'deleteUrl' => route('project.destroy', ['project_uuid' => $project->uuid]),
        ]);
    }

    public function update(Request $request, string $project_uuid): RedirectResponse
    {
        $project = Project::where('team_id', currentTeam()->id)->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('update', $project);

        $validated = Validator::make(
            $request->all(),
            [
                'name' => ValidationPatterns::nameRules(),
                'description' => ValidationPatterns::descriptionRules(),
            ],
            ValidationPatterns::combinedMessages(),
        )->validate();

        $project->update($validated);

        return back()->with('success', 'Project updated.');
    }

    public function destroy(string $project_uuid): RedirectResponse
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $this->authorize('delete', $project);

        if (! $project->isEmpty()) {
            return back()->with('error', "Project {$project->name} has resources defined, please delete them first.");
        }

        $project->delete();

        return redirect()->route('project.index');
    }
}
