<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\CloudInitScript;
use App\Rules\ValidCloudInitYaml;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;

class SecurityCloudInitScriptsController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CloudInitScript::class);

        $scripts = CloudInitScript::ownedByCurrentTeam()->orderBy('created_at', 'desc')->get();

        return Inertia::render('Security/CloudInitScripts', [
            'canCreate' => Gate::forUser($request->user())->allows('create', CloudInitScript::class),
            'scripts' => $scripts->map(fn (CloudInitScript $script) => [
                'id' => $script->id,
                'name' => $script->name,
                'script' => $script->script,
                'createdAgo' => $script->created_at->diffForHumans(),
                'canUpdate' => Gate::forUser($request->user())->allows('update', $script),
                'canDelete' => Gate::forUser($request->user())->allows('delete', $script),
                'updateUrl' => route('security.cloud-init-scripts.update', ['id' => $script->id]),
                'destroyUrl' => route('security.cloud-init-scripts.destroy', ['id' => $script->id]),
            ]),
            'storeUrl' => route('security.cloud-init-scripts.store'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', CloudInitScript::class);

        $validated = $this->validated($request);

        CloudInitScript::create([
            'team_id' => currentTeam()->id,
            'name' => $validated['name'],
            'script' => $validated['script'],
        ]);

        return back()->with('success', 'Cloud-init script created successfully.');
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($id);
        $this->authorize('update', $script);

        $validated = $this->validated($request);
        $script->update($validated);

        return back()->with('success', 'Cloud-init script updated successfully.');
    }

    public function destroy(int $id): RedirectResponse
    {
        $script = CloudInitScript::ownedByCurrentTeam()->findOrFail($id);
        $this->authorize('delete', $script);

        $script->delete();

        return back()->with('success', 'Cloud-init script deleted successfully.');
    }

    /**
     * @return array<string, string>
     */
    private function validated(Request $request): array
    {
        return Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'script' => ['required', 'string', new ValidCloudInitYaml],
        ], [
            'name.required' => 'Script name is required.',
            'name.max' => 'Script name cannot exceed 255 characters.',
            'script.required' => 'Cloud-init script content is required.',
        ])->validate();
    }
}
