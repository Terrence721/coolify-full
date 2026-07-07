<?php

declare(strict_types=1);

namespace App\Livewire\Project\Database\Dragonfly;

use App\Models\StandaloneDragonfly;
use App\Support\ValidationPatterns;
use App\Traits\HasDatabaseGeneralForm;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;
    use HasDatabaseGeneralForm;

    public StandaloneDragonfly $database;

    public string $dragonflyPassword;

    public function getListeners(): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }
        $team = $user->currentTeam();
        if (! $team) {
            return [];
        }

        return [
            "echo-private:team.{$team->id},DatabaseProxyStopped" => 'databaseProxyStopped',
        ];
    }

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'dragonflyPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->dragonflyPassword !== $this->database->dragonfly_password,
            ),
            'image' => 'required|string',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
            'customDockerRunOptions' => 'nullable|string',
            'isLogDrainEnabled' => 'nullable|boolean',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            ValidationPatterns::portMappingMessages(),
            [
                ...ValidationPatterns::databasePasswordMessages('dragonflyPassword', 'Dragonfly Password'),
                'image.required' => 'The Docker Image field is required.',
                'image.string' => 'The Docker Image must be a string.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'publicPort.min' => 'The Public Port must be at least 1.',
                'publicPort.max' => 'The Public Port must not exceed 65535.',
                'publicPortTimeout.integer' => 'The Public Port Timeout must be an integer.',
                'publicPortTimeout.min' => 'The Public Port Timeout must be at least 1.',
            ]
        );
    }

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->dragonfly_password = $this->dragonflyPassword;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort ?: null;
            $this->database->public_port_timeout = $this->publicPortTimeout ?: null;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->save();
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->dragonflyPassword = $this->database->dragonfly_password;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicPortTimeout = $this->database->public_port_timeout;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
        }
    }

    public function databaseProxyStopped(): void
    {
        $this->database->refresh();
        $this->isPublic = $this->database->is_public;
        $this->publicPort = $this->database->public_port;
        $this->publicPortTimeout = $this->database->public_port_timeout;
        $this->dispatch('databaseUpdated');
    }
}
