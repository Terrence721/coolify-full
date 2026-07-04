<?php

declare(strict_types=1);

namespace App\Livewire\Security;

use App\Models\CloudInitScript;
use App\Rules\ValidCloudInitYaml;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class CloudInitScriptForm extends Component
{
    use AuthorizesRequests;

    public bool $modal_mode = true;

    public ?int $scriptId = null;

    public string $name = '';

    public string $script = '';

    public function mount(?int $scriptId = null)
    {
        if ($scriptId) {
            $this->scriptId = $scriptId;
            $cloudInitScript = CloudInitScript::ownedByCurrentTeam()->findOrFail($scriptId);
            $this->authorize('update', $cloudInitScript);

            $this->name = $cloudInitScript->name;
            $this->script = $cloudInitScript->script;
        } else {
            $this->authorize('create', CloudInitScript::class);
        }
    }

    protected function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'script' => ['required', 'string', new ValidCloudInitYaml],
        ];
    }

    protected function messages(): array
    {
        return [
            'name.required' => 'Script name is required.',
            'name.max' => 'Script name cannot exceed 255 characters.',
            'script.required' => 'Cloud-init script content is required.',
        ];
    }

    public function save()
    {
        $this->validate();

        try {
            if ($this->scriptId) {
                // Update existing script
                $cloudInitScript = CloudInitScript::ownedByCurrentTeam()->findOrFail($this->scriptId);
                $this->authorize('update', $cloudInitScript);

                $cloudInitScript->update([
                    'name' => $this->name,
                    'script' => $this->script,
                ]);

                $message = 'Cloud-init script updated successfully.';
            } else {
                // Create new script
                $this->authorize('create', CloudInitScript::class);

                CloudInitScript::create([
                    'team_id' => currentTeam()->id,
                    'name' => $this->name,
                    'script' => $this->script,
                ]);

                $message = 'Cloud-init script created successfully.';
            }

            // Only reset fields if creating (not editing)
            if (! $this->scriptId) {
                $this->reset(['name', 'script']);
            }

            $this->dispatch('scriptSaved');
            $this->dispatch('success', $message);

            if ($this->modal_mode) {
                $this->dispatch('closeModal');
            }
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.security.cloud-init-script-form');
    }
}
