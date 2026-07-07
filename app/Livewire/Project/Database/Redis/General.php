<?php

declare(strict_types=1);

namespace App\Livewire\Project\Database\Redis;

use App\Models\StandaloneRedis;
use App\Support\ValidationPatterns;
use App\Traits\HasDatabaseGeneralForm;
use Exception;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;
    use HasDatabaseGeneralForm;

    public StandaloneRedis $database;

    public ?string $redisConf = null;

    public string $redisUsername;

    public string $redisPassword;

    public string $redisVersion;

    protected $listeners = [
        'envsUpdated' => 'refresh',
    ];

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'redisConf' => 'nullable',
            'image' => 'required',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
            'redisUsername' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->redisUsername !== $this->database->redis_username,
            ),
            'redisPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->redisPassword !== $this->database->redis_password,
            ),
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            ValidationPatterns::portMappingMessages(),
            [
                'name.required' => 'The Name field is required.',
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'publicPort.min' => 'The Public Port must be at least 1.',
                'publicPort.max' => 'The Public Port must not exceed 65535.',
                'publicPortTimeout.integer' => 'The Public Port Timeout must be an integer.',
                'publicPortTimeout.min' => 'The Public Port Timeout must be at least 1.',
                ...ValidationPatterns::databaseIdentifierMessages('redisUsername', 'Redis Username'),
                ...ValidationPatterns::databasePasswordMessages('redisPassword', 'Redis Password'),
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'redisConf' => 'Redis Configuration',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'publicPortTimeout' => 'Public Port Timeout',
        'customDockerRunOptions' => 'Custom Docker Options',
        'redisUsername' => 'Redis Username',
        'redisPassword' => 'Redis Password',
    ];

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->redis_conf = $this->redisConf;
            $this->database->image = $this->image;
            $this->database->ports_mappings = $this->portsMappings;
            $this->database->is_public = $this->isPublic;
            $this->database->public_port = $this->publicPort ?: null;
            $this->database->public_port_timeout = $this->publicPortTimeout ?: null;
            $this->database->is_log_drain_enabled = $this->isLogDrainEnabled;
            $this->database->custom_docker_run_options = $this->customDockerRunOptions;
            $this->database->save();
        } else {
            $this->name = $this->database->name;
            $this->description = $this->database->description;
            $this->redisConf = $this->database->redis_conf;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicPortTimeout = $this->database->public_port_timeout;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
            $this->redisVersion = $this->database->getRedisVersion();
            $this->redisUsername = $this->database->redis_username;
            $this->redisPassword = $this->database->redis_password;
        }
    }

    public function submit()
    {
        try {
            $this->authorize('manageEnvironment', $this->database);

            if ($this->portsMappings) {
                $this->portsMappings = str($this->portsMappings)->replace(' ', '')->trim()->toString();
            }
            $this->syncData(true);

            if (version_compare($this->redisVersion, '6.0', '>=')) {
                $this->database->runtime_environment_variables()->updateOrCreate(
                    ['key' => 'REDIS_USERNAME'],
                    ['value' => $this->redisUsername, 'resourceable_id' => $this->database->id]
                );
            }
            $this->database->runtime_environment_variables()->updateOrCreate(
                ['key' => 'REDIS_PASSWORD'],
                ['value' => $this->redisPassword, 'resourceable_id' => $this->database->id]
            );

            $this->dispatch('success', 'Database updated.');
            $this->dispatch('databaseUpdated');
        } catch (Exception $e) {
            return handleError($e, $this);
        } finally {
            $this->dispatch('refreshEnvs');
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.project.database.redis.general');
    }

    public function isSharedVariable($name)
    {
        return $this->database->runtime_environment_variables()->where('key', $name)->where('is_shared', true)->exists();
    }
}
