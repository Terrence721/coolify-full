<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SharedVariablesController extends BaseController
{
    use AuthorizesRequests;

    public function index(): Response
    {
        return Inertia::render('SharedVariables/Index', [
            'links' => [
                [
                    'href' => route('shared-variables.team.index'),
                    'title' => 'Team wide',
                    'description' => 'Usable for all resources in a team.',
                ],
                [
                    'href' => route('shared-variables.project.index'),
                    'title' => 'Project wide',
                    'description' => 'Usable for all resources in a project.',
                ],
                [
                    'href' => route('shared-variables.environment.index'),
                    'title' => 'Environment wide',
                    'description' => 'Usable for all resources in an environment.',
                ],
                [
                    'href' => route('shared-variables.server.index'),
                    'title' => 'Server wide',
                    'description' => 'Usable for all resources in a server.',
                ],
            ],
        ]);
    }

    public function environment(): Response
    {
        $projects = Project::ownedByCurrentTeamCached()->map(fn ($project) => [
            'name' => $project->name,
            'description' => $project->description,
            'environments' => $project->environments->map(fn ($environment) => [
                'name' => $environment->name,
                'description' => $environment->description,
                'href' => route('shared-variables.environment.show', [
                    'project_uuid' => $project->uuid,
                    'environment_uuid' => $environment->uuid,
                ]),
            ]),
        ]);

        return Inertia::render('SharedVariables/Environment/Index', [
            'projects' => $projects,
        ]);
    }

    public function project(): Response
    {
        $projects = Project::ownedByCurrentTeamCached()->map(fn ($project) => [
            'name' => $project->name,
            'description' => $project->description,
            'href' => route('shared-variables.project.show', ['project_uuid' => $project->uuid]),
        ]);

        return Inertia::render('SharedVariables/Project/Index', [
            'projects' => $projects,
        ]);
    }

    public function server(): Response
    {
        $servers = Server::ownedByCurrentTeamCached()->map(fn ($server) => [
            'name' => $server->name,
            'description' => $server->description,
            'href' => route('shared-variables.server.show', ['server_uuid' => $server->uuid]),
        ]);

        return Inertia::render('SharedVariables/Server/Index', [
            'servers' => $servers,
        ]);
    }

    public function teamShow(): Response
    {
        return $this->renderShow('team', currentTeam(), 'shared-variables.team', [], 'SharedVariables/Team/Index', 'your team');
    }

    public function projectShow(string $project_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();

        return $this->renderShow('project', $project, 'shared-variables.project', ['project_uuid' => $project_uuid], 'SharedVariables/Project/Show', $project->name);
    }

    public function environmentShow(string $project_uuid, string $environment_uuid): Response
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', $project_uuid)->firstOrFail();
        $environment = $project->environments()->where('uuid', $environment_uuid)->firstOrFail();
        $ids = ['project_uuid' => $project_uuid, 'environment_uuid' => $environment_uuid];

        return $this->renderShow('environment', $environment, 'shared-variables.environment', $ids, 'SharedVariables/Environment/Show', $environment->name);
    }

    public function serverShow(string $server_uuid): Response
    {
        $server = Server::ownedByCurrentTeam()->where('uuid', $server_uuid)->firstOrFail();

        return $this->renderShow('server', $server, 'shared-variables.server', ['server_uuid' => $server_uuid], 'SharedVariables/Server/Show', $server->name);
    }

    /**
     * @param  array<string, string>  $routeIds
     */
    private function renderShow(string $scope, Team|Project|Environment|Server $owner, string $routeBase, array $routeIds, string $component = '', string $label = ''): Response
    {
        $excludedKeys = $scope === 'server' ? ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'] : [];

        $variables = $owner->environment_variables()
            ->whereNotIn('key', $excludedKeys)
            ->get()
            ->sortBy('key')
            ->values();

        return Inertia::render($component, [
            'label' => $label,
            'scope' => $scope,
            'canUpdate' => auth()->user()?->can('update', $owner) ?? false,
            'variables' => $variables->map(fn (SharedEnvironmentVariable $variable) => [
                'id' => $variable->id,
                'key' => $variable->key,
                'value' => $variable->value,
                'comment' => $variable->comment,
                'isMultiline' => (bool) $variable->is_multiline,
                'isLiteral' => (bool) $variable->is_literal,
                'isShownOnce' => (bool) $variable->is_shown_once,
                'updateUrl' => route("{$routeBase}.update", [...$routeIds, 'variable_id' => $variable->id]),
                'lockUrl' => route("{$routeBase}.lock", [...$routeIds, 'variable_id' => $variable->id]),
                'deleteUrl' => route("{$routeBase}.destroy", [...$routeIds, 'variable_id' => $variable->id]),
            ]),
            'devViewText' => $this->formatDevView($variables),
            'storeUrl' => route("{$routeBase}.store", $routeIds),
            'bulkUpdateUrl' => route("{$routeBase}.bulk-update", $routeIds),
        ]);
    }

    public function storeVariable(Request $request): RedirectResponse
    {
        [$owner, $scope] = $this->resolveOwner($request);
        $this->authorize('update', $owner);

        $validated = Validator::make($request->all(), [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'comment' => 'nullable|string|max:256',
        ], ValidationPatterns::environmentVariableKeyMessages())->validate();

        $key = ValidationPatterns::normalizeEnvironmentVariableKey($validated['key']);

        if ($scope === 'server' && in_array($key, ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])) {
            return back()->with('error', 'Cannot create predefined variable.');
        }

        if ($owner->environment_variables()->where('key', $key)->exists()) {
            return back()->with('error', 'Variable already exists.');
        }

        $owner->environment_variables()->create([
            'key' => $key,
            'value' => $validated['value'] ?? '',
            'is_multiline' => $validated['is_multiline'],
            'is_literal' => $validated['is_literal'],
            'comment' => $validated['comment'] ?? null,
            'type' => $scope,
            'team_id' => currentTeam()->id,
        ]);

        return back()->with('success', 'Environment variable created.');
    }

    public function updateVariable(Request $request): RedirectResponse
    {
        [$owner] = $this->resolveOwner($request);
        $this->authorize('update', $owner);

        $variable = $owner->environment_variables()->findOrFail((int) $request->route('variable_id'));

        $validated = Validator::make($request->all(), [
            'key' => ValidationPatterns::environmentVariableKeyRules(),
            'value' => 'nullable',
            'comment' => 'nullable|string|max:256',
            'is_multiline' => 'required|boolean',
            'is_literal' => 'required|boolean',
            'is_shown_once' => 'required|boolean',
        ], ValidationPatterns::environmentVariableKeyMessages())->validate();

        $variable->update([
            'key' => ValidationPatterns::normalizeEnvironmentVariableKey($validated['key']),
            'value' => $validated['value'] ?? '',
            'comment' => $validated['comment'] ?? null,
            'is_multiline' => $validated['is_multiline'],
            'is_literal' => $validated['is_literal'],
            'is_shown_once' => $validated['is_shown_once'],
        ]);

        return back()->with('success', 'Environment variable updated.');
    }

    public function lockVariable(Request $request): RedirectResponse
    {
        [$owner] = $this->resolveOwner($request);
        $this->authorize('update', $owner);

        $variable = $owner->environment_variables()->findOrFail((int) $request->route('variable_id'));
        $variable->is_shown_once = true;
        $variable->save();

        return back()->with('success', 'Environment variable locked.');
    }

    public function destroyVariable(Request $request): RedirectResponse
    {
        [$owner] = $this->resolveOwner($request);
        $this->authorize('update', $owner);

        $variable = $owner->environment_variables()->findOrFail((int) $request->route('variable_id'));
        $variable->delete();

        return back()->with('success', 'Environment variable deleted successfully.');
    }

    public function bulkUpdateVariables(Request $request): RedirectResponse
    {
        [$owner, $scope] = $this->resolveOwner($request);
        $this->authorize('update', $owner);

        $validated = Validator::make($request->all(), [
            'variables' => 'nullable|string',
        ])->validate();

        $excludedKeys = $scope === 'server' ? ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'] : [];
        $parsed = parseEnvFormatToArray($validated['variables'] ?? '');

        $changesMade = DB::transaction(function () use ($owner, $parsed, $scope, $excludedKeys) {
            $keysToKeep = array_diff(array_keys($parsed), $excludedKeys);

            $variablesToDelete = $owner->environment_variables()->whereNotIn('key', $keysToKeep)->whereNotIn('key', $excludedKeys)->get();
            $deletedCount = $variablesToDelete->count();
            if ($deletedCount > 0) {
                $owner->environment_variables()->whereNotIn('key', $keysToKeep)->whereNotIn('key', $excludedKeys)->delete();
            }

            $updatedCount = 0;
            foreach ($parsed as $key => $data) {
                if (in_array($key, $excludedKeys)) {
                    continue;
                }

                $value = is_array($data) ? ($data['value'] ?? '') : $data;
                $comment = is_array($data) ? ($data['comment'] ?? null) : null;

                $found = $owner->environment_variables()->where('key', $key)->first();
                if ($found) {
                    if (! $found->is_shown_once && ! $found->is_multiline && ($found->value !== $value || $found->comment !== $comment)) {
                        $found->value = $value;
                        $found->comment = $comment;
                        $found->save();
                        $updatedCount++;
                    }
                } else {
                    $owner->environment_variables()->create([
                        'key' => $key,
                        'value' => $value,
                        'comment' => $comment,
                        'is_multiline' => false,
                        'is_literal' => false,
                        'type' => $scope,
                        'team_id' => currentTeam()->id,
                    ]);
                    $updatedCount++;
                }
            }

            return $deletedCount > 0 || $updatedCount > 0;
        });

        if ($changesMade) {
            return back()->with('success', 'Environment variables updated.');
        }

        return back();
    }

    /**
     * @return array{0: Team|Project|Environment|Server, 1: string}
     */
    private function resolveOwner(Request $request): array
    {
        if ($request->route('server_uuid')) {
            $server = Server::ownedByCurrentTeam()->where('uuid', $request->route('server_uuid'))->firstOrFail();

            return [$server, 'server'];
        }

        if ($request->route('environment_uuid')) {
            $project = Project::ownedByCurrentTeam()->where('uuid', $request->route('project_uuid'))->firstOrFail();
            $environment = $project->environments()->where('uuid', $request->route('environment_uuid'))->firstOrFail();

            return [$environment, 'environment'];
        }

        if ($request->route('project_uuid')) {
            $project = Project::ownedByCurrentTeam()->where('uuid', $request->route('project_uuid'))->firstOrFail();

            return [$project, 'project'];
        }

        return [currentTeam(), 'team'];
    }

    /**
     * @param  Collection<int, SharedEnvironmentVariable>  $variables
     */
    private function formatDevView($variables): string
    {
        return $variables->map(function (SharedEnvironmentVariable $item) {
            if ($item->is_shown_once) {
                return "{$item->key}=(Locked Secret, delete and add again to change)";
            }
            if ($item->is_multiline) {
                return "{$item->key}=(Multiline environment variable, edit in normal view)";
            }

            return "{$item->key}={$item->value}";
        })->join("\n");
    }
}
