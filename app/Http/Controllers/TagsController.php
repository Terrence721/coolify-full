<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Controllers\Api\DeployController;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Tag;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class TagsController extends Controller
{
    public function show(?string $tagName = null): Response
    {
        $tags = Tag::ownedByCurrentTeam()->get()->unique('name')->sortBy('name')->values();

        $props = [
            'tags' => $tags->map(fn ($t) => [
                'name' => $t->name,
                'href' => route('tags.show', ['tagName' => $t->name]),
            ]),
            'tagName' => $tagName,
            'tag' => null,
        ];

        if (blank($tagName)) {
            return Inertia::render('Tags/Show', $props);
        }

        $tag = $tags->firstWhere('name', $tagName);

        if (! $tag) {
            return Inertia::render('Tags/Show', $props);
        }

        $applications = $tag->applications()->get();
        $services = $tag->services()->get();

        $props['tag'] = [
            'name' => $tag->name,
            'webhook' => generateTagDeployWebhook($tag->name),
            'redeployUrl' => route('tags.redeploy', ['tagName' => $tag->name]),
        ];
        $props['applications'] = $applications->map(fn ($app) => [
            'name' => $app->name,
            'description' => $app->description,
            'projectEnvironment' => $app->project()->name.'/'.$app->environment->name,
            'href' => $app->link(),
        ]);
        $props['services'] = $services->map(fn ($service) => [
            'name' => $service->name,
            'description' => $service->description,
            'projectEnvironment' => $service->project()->name.'/'.$service->environment->name,
            'href' => $service->link(),
        ]);
        $props['deploymentsPerTagPerServer'] = $this->getDeployments($applications->pluck('id'));

        return Inertia::render('Tags/Show', $props);
    }

    public function redeploy(string $tagName): RedirectResponse
    {
        $tag = Tag::ownedByCurrentTeam()->get()->unique('name')->firstWhere('name', $tagName);

        if (! $tag) {
            return back()->with('error', 'Tag not found.');
        }

        $tag->applications()->get()->each(function ($resource) {
            (new DeployController)->deploy_resource($resource);
        });
        $tag->services()->get()->each(function ($resource) {
            (new DeployController)->deploy_resource($resource);
        });

        return back()->with('success', 'Mass deployment started.');
    }

    /**
     * @param  Collection<int, int>  $applicationIds
     * @return array<string, mixed>
     */
    private function getDeployments(Collection $applicationIds): array
    {
        return ApplicationDeploymentQueue::whereIn('status', ['in_progress', 'queued'])
            ->whereIn('application_id', $applicationIds)
            ->get([
                'id', 'application_id', 'application_name', 'deployment_url',
                'pull_request_id', 'server_name', 'server_id', 'status',
            ])
            ->sortBy('id')
            ->groupBy('server_name')
            ->toArray();
    }
}
