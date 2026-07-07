<?php

declare(strict_types=1);

namespace App\Livewire\Project\Database\Mysql;

use App\Models\StandaloneMysql;
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

    public StandaloneMysql $database;

    public string $mysqlRootPassword;

    public string $mysqlUser;

    public string $mysqlPassword;

    public string $mysqlDatabase;

    public ?string $mysqlConf = null;

    protected function rules(): array
    {
        return [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'mysqlRootPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->mysqlRootPassword !== $this->database->mysql_root_password,
            ),
            'mysqlUser' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mysqlUser !== $this->database->mysql_user,
            ),
            'mysqlPassword' => ValidationPatterns::databasePasswordRules(
                enforcePattern: $this->mysqlPassword !== $this->database->mysql_password,
            ),
            'mysqlDatabase' => ValidationPatterns::databaseIdentifierRules(
                enforcePattern: $this->mysqlDatabase !== $this->database->mysql_database,
            ),
            'mysqlConf' => 'nullable',
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
                ...ValidationPatterns::databasePasswordMessages('mysqlRootPassword', 'Root Password'),
                ...ValidationPatterns::databaseIdentifierMessages('mysqlUser', 'MySQL User'),
                ...ValidationPatterns::databasePasswordMessages('mysqlPassword', 'MySQL Password'),
                ...ValidationPatterns::databaseIdentifierMessages('mysqlDatabase', 'MySQL Database'),
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
        'mysqlRootPassword' => 'Root Password',
        'mysqlUser' => 'User',
        'mysqlPassword' => 'Password',
        'mysqlDatabase' => 'Database',
        'mysqlConf' => 'MySQL Configuration',
        'image' => 'Image',
        'portsMappings' => 'Port Mapping',
        'isPublic' => 'Is Public',
        'publicPort' => 'Public Port',
        'publicPortTimeout' => 'Public Port Timeout',
        'customDockerRunOptions' => 'Custom Docker Run Options',
    ];

    public function syncData(bool $toModel = false)
    {
        if ($toModel) {
            $this->validate();
            $this->database->name = $this->name;
            $this->database->description = $this->description;
            $this->database->mysql_root_password = $this->mysqlRootPassword;
            $this->database->mysql_user = $this->mysqlUser;
            $this->database->mysql_password = $this->mysqlPassword;
            $this->database->mysql_database = $this->mysqlDatabase;
            $this->database->mysql_conf = $this->mysqlConf;
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
            $this->mysqlRootPassword = $this->database->mysql_root_password;
            $this->mysqlUser = $this->database->mysql_user;
            $this->mysqlPassword = $this->database->mysql_password;
            $this->mysqlDatabase = $this->database->mysql_database;
            $this->mysqlConf = $this->database->mysql_conf;
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
        return view('livewire.project.database.mysql.general');
    }
}
