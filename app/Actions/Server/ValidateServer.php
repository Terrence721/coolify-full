<?php

declare(strict_types=1);

namespace App\Actions\Server;

use App\Models\Server;
use Illuminate\Support\Stringable;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidateServer
{
    use AsAction;

    public string $jobQueue = 'high';

    public bool $uptime = false;

    public ?string $error = null;

    public bool|Stringable $supported_os_type = false;

    public bool $docker_installed = false;

    public bool $docker_compose_installed = false;

    public bool $docker_version = false;

    public function handle(Server $server): string
    {
        $server->update([
            'validation_logs' => null,
        ]);
        ['uptime' => $this->uptime, 'error' => $error] = $server->validateConnection();
        if (! $this->uptime) {
            $sanitizedError = htmlspecialchars($error ?? '', ENT_QUOTES, 'UTF-8');
            $this->error = 'Server is not reachable. Please validate your configuration and connection.<br>Check this <a target="_blank" class="text-black underline dark:text-white" href="https://coolify.io/docs/knowledge-base/server/openssh">documentation</a> for further help. <br><br><div class="text-error">Error: '.$sanitizedError.'</div>';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
        $this->supported_os_type = $server->validateOS();
        if (! $this->supported_os_type) {
            $this->error = 'Server OS type is not supported. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }

        $validationResult = $server->validatePrerequisites();
        if (! $validationResult['success']) {
            $missingCommands = implode(', ', $validationResult['missing']);
            $this->error = "Prerequisites ({$missingCommands}) are not installed. Please install them before continuing or use the validation with installation endpoint.";
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }

        $this->docker_installed = $server->validateDockerEngine();
        $this->docker_compose_installed = $server->validateDockerCompose();
        if (! $this->docker_installed || ! $this->docker_compose_installed) {
            $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
        $this->docker_version = $server->validateDockerEngineVersion();

        if ($this->docker_version) {
            return 'OK';
        } else {
            $this->error = 'Docker Engine is not installed. Please install Docker manually before continuing: <a target="_blank" class="text-black underline dark:text-white" href="https://docs.docker.com/engine/install/#server">documentation</a>.';
            $server->update([
                'validation_logs' => $this->error,
            ]);
            throw new \Exception($this->error);
        }
    }
}
