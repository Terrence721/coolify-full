<?php

declare(strict_types=1);

namespace App\Livewire\Project\Database\Mariadb;

use App\Models\StandaloneMariadb;
use App\Support\ValidationPatterns;
use App\Traits\HasDatabaseGeneralForm;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;

class General extends Component
{
    use AuthorizesRequests;
    use HasDatabaseGeneralForm;

    public StandaloneMariadb $database;

    public string $mariadbRootPassword;

    public string $mariadbUser;

    public string $mariadbPassword;

    public string $mariadbDatabase;

    public ?string $mariadbConf = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'mariadbRootPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->mariadbRootPassword !== $this->database->mariadb_root_password,
            ),
            'mariadbUser' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mariadbUser !== $this->database->mariadb_user,
            ),
            'mariadbPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->mariadbPassword !== $this->database->mariadb_password,
            ),
            'mariadbDatabase' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mariadbDatabase !== $this->database->mariadb_database,
            ),
            'mariadbConf' => 'nullable',
            'image' => 'required',
            'portsMappings' => ValidationPatterns::portMappingRules(),
            'isPublic' => 'nullable|boolean',
            'publicPort' => 'nullable|integer|min:1|max:65535',
            'publicPortTimeout' => 'nullable|integer|min:1',
            'isLogDrainEnabled' => 'nullable|boolean',
            'customDockerRunOptions' => 'nullable',
        ];
    }

    protected function messages(): array
    {
        return array_merge(
            ValidationPatterns::combinedMessages(),
            ValidationPatterns::portMappingMessages(),
            [
                'name.required' => 'The Name field is required.',
                ...ValidationPatterns::databasePasswordMessages('mariadbRootPassword', 'Root Password'),
                ...ValidationPatterns::databaseIdentifierMessages('mariadbUser', 'MariaDB User'),
                ...ValidationPatterns::databasePasswordMessages('mariadbPassword', 'MariaDB Password'),
                ...ValidationPatterns::databaseIdentifierMessages('mariadbDatabase', 'MariaDB Database'),
                'image.required' => 'The Docker Image field is required.',
                'publicPort.integer' => 'The Public Port must be an integer.',
                'publicPort.min' => 'The Public Port must be at least 1.',
                'publicPort.max' => 'The Public Port must not exceed 65535.',
                'publicPortTimeout.integer' => 'The Public Port Timeout must be an integer.',
                'publicPortTimeout.min' => 'The Public Port Timeout must be at least 1.',
            ]
        );
    }

    protected $validationAttributes = [
        'name' => 'Name',
        'description' => 'Description',
        'mariadbRootPassword' => 'Root Password',
        'mariadbUser' => 'User',
        'mariadbPassword' => 'Password',
        'mariadbDatabase' => 'Database',
        'mariadbConf' => 'MariaDB Configuration',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'publicPortTimeout' => 'Public Port Timeout',
        'customDockerRunOptions' => 'Custom Docker Options',
    ];

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->mariadb_root_password = $this->mariadbRootPassword;
            $this->database->mariadb_user = $this->mariadbUser;
            $this->database->mariadb_password = $this->mariadbPassword;
            $this->database->mariadb_database = $this->mariadbDatabase;
            $this->database->mariadb_conf = $this->mariadbConf;
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
            $this->mariadbRootPassword = $this->database->mariadb_root_password;
            $this->mariadbUser = $this->database->mariadb_user;
            $this->mariadbPassword = $this->database->mariadb_password;
            $this->mariadbDatabase = $this->database->mariadb_database;
            $this->mariadbConf = $this->database->mariadb_conf;
            $this->image = $this->database->image;
            $this->portsMappings = $this->database->ports_mappings;
            $this->isPublic = $this->database->is_public;
            $this->publicPort = $this->database->public_port;
            $this->publicPortTimeout = $this->database->public_port_timeout;
            $this->isLogDrainEnabled = $this->database->is_log_drain_enabled;
            $this->customDockerRunOptions = $this->database->custom_docker_run_options;
        }
    }

    public function render(): Factory|View
    {
        return view('livewire.project.database.mariadb.general');
    }
}
