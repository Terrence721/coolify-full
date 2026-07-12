<?php

declare(strict_types=1);

namespace App\Livewire\Project\Resource;

use App\Models\Project;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Livewire\Component;

/**
 * Thin Livewire shell for the 3 GitHub-dependent creation flows (public/private-gh-app/
 * private-deploy-key) that `App\Http\Controllers\ProjectResourceCreateController` (the React/
 * Inertia port of the rest of the "+ New" wizard) redirects to rather than porting itself — see
 * that controller's docblock for why. Extracted from the git branches of the original
 * `App\Livewire\Project\Resource\Create`'s view; the 3 nested `New\*` components are untouched
 * and still resolve their own `project_uuid`/`environment_uuid`/`destination` from the route/query
 * string exactly as before.
 */
class GitCreate extends Component
{
    private const GIT_TYPES = ['public', 'private-gh-app', 'private-deploy-key'];

    public ?string $type = null;

    public ?Project $project = null;

    public function mount(): ?RedirectResponse
    {
        $type = (string) request()->query('type');

        $project = currentTeam()->load(['projects'])->projects->where('uuid', request()->route('project_uuid'))->first();
        if (! $project) {
            return redirect()->route('dashboard');
        }
        $this->project = $project;

        $environment = $project->load(['environments'])->environments->where('uuid', request()->route('environment_uuid'))->first();
        if (! $environment) {
            return redirect()->route('dashboard');
        }

        if (! in_array($type, self::GIT_TYPES, true)) {
            return redirect()->route('project.resource.create', [
                'project_uuid' => $project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        }

        $this->type = $type;

        return null;
    }

    public function render(): Factory|View
    {
        return view('livewire.project.resource.git-create');
    }
}
