<?php

declare(strict_types=1);

namespace App\Actions\Database;

use App\Helpers\SslHelper;
use App\Models\LocalFileVolume;
use App\Models\Server;
use App\Models\SslCertificate;
use App\Models\StandaloneKeydb;
use App\Traits\GeneratesLocalPersistentVolumes;
use Illuminate\Database\Eloquent\Collection;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class StartKeydb
{
    use AsAction, GeneratesLocalPersistentVolumes;

    public StandaloneKeydb $database;

    /** @var array<int, string> */
    public array $commands = [];

    public string $configuration_dir;

    private ?SslCertificate $ssl_certificate = null;

    public function handle(StandaloneKeydb $database): mixed
    {
        $this->database = $database;

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $this->commands = [
            "echo 'Starting database.'",
            "echo 'Creating directories.'",
            "mkdir -p $this->configuration_dir",
            "echo 'Directories created successfully.'",
        ];

        if (! $this->database->enable_ssl) {
            $this->disable_ssl();
        } else {
            $server = data_get($this->database, 'destination.server');
            if (! $server instanceof Server) {
                return null;
            }
            if (! $this->setup_ssl($server)) {
                return null;
            }
        }

        $container_name = $this->database->uuid;
        $this->configuration_dir = database_configuration_dir().'/'.$container_name;

        $persistent_storages = $this->generate_local_persistent_volumes();
        $persistent_file_volumes = $this->database->fileStorages()->get();
        $volume_names = $this->generate_local_persistent_volumes_only_volume_names();
        $environment_variables = $this->generate_environment_variables();
        $this->add_custom_keydb();

        $startCommand = $this->buildStartCommand();

        $docker_compose = $this->build_docker_compose($container_name, $startCommand, $environment_variables, $persistent_storages, $persistent_file_volumes, $volume_names);

        $server = data_get($this->database, 'destination.server');

        $docker_compose = Yaml::dump($docker_compose, 10);
        $this->build_commands($container_name, $docker_compose);

        return remote_process($this->commands, $server, callEventOnFinish: 'DatabaseStatusChanged');
    }

    private function disable_ssl(): void
    {
        $this->commands[] = "rm -rf $this->configuration_dir/ssl";
        $this->database->sslCertificates()->delete();
        $this->database->fileStorages()
            ->where('resource_type', $this->database->getMorphClass())
            ->where('resource_id', $this->database->id)
            ->get()
            ->filter(function ($storage) {
                return in_array($storage->mount_path, [
                    '/etc/keydb/certs/server.crt',
                    '/etc/keydb/certs/server.key',
                ]);
            })
            ->each(function ($storage) {
                $storage->delete();
            });
    }

    private function setup_ssl(Server $server): bool
    {
        $this->commands[] = "echo 'Setting up SSL for this database.'";
        $this->commands[] = "mkdir -p $this->configuration_dir/ssl";

        $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();

        if (! $caCert) {
            $server->generateCaCertificate();
            $caCert = $server->sslCertificates()->where('is_ca_certificate', true)->first();
        }

        if (! $caCert) {
            $this->dispatch('error', 'No CA certificate found for this database. Please generate a CA certificate for this server in the server/advanced page.');

            return false;
        }

        $this->ssl_certificate = $this->database->sslCertificates()->first();

        if (! $this->ssl_certificate) {
            $this->commands[] = "echo 'No SSL certificate found, generating new SSL certificate for this database.'";
            $this->ssl_certificate = SslHelper::generateSslCertificate(
                commonName: $this->database->uuid,
                resourceType: $this->database->getMorphClass(),
                resourceId: $this->database->id,
                serverId: $server->id,
                caCert: $caCert->ssl_certificate,
                caKey: $caCert->ssl_private_key,
                configurationDir: $this->configuration_dir,
                mountPath: '/etc/keydb/certs',
            );
        }

        return true;
    }

    /**
     * @param  array<int, string>  $environment_variables
     * @param  array<int, mixed>  $persistent_storages
     * @param  Collection<int, LocalFileVolume>  $persistent_file_volumes
     * @param  array<int, mixed>  $volume_names
     * @return array<string, mixed>
     */
    private function build_docker_compose(string $container_name, string $startCommand, array $environment_variables, array $persistent_storages, $persistent_file_volumes, array $volume_names): array
    {
        $docker_compose = [
            'services' => [
                $container_name => [
                    'image' => $this->database->image,
                    'command' => $startCommand,
                    'container_name' => $container_name,
                    'environment' => $environment_variables,
                    'restart' => RESTART_MODE,
                    'networks' => [
                        $this->database->destination->network,
                    ],
                    'labels' => defaultDatabaseLabels($this->database)->toArray(),
                    'healthcheck' => $this->database->healthCheckConfiguration([
                        'CMD', 'keydb-cli', '--pass', (string) $this->database->keydb_password, 'ping',
                    ]),
                    'mem_limit' => $this->database->limits_memory,
                    'memswap_limit' => $this->database->limits_memory_swap,
                    'mem_swappiness' => $this->database->limits_memory_swappiness,
                    'mem_reservation' => $this->database->limits_memory_reservation,
                    'cpus' => (float) $this->database->limits_cpus,
                    'cpu_shares' => $this->database->limits_cpu_shares,
                ],
            ],
            'networks' => [
                $this->database->destination->network => [
                    'external' => true,
                    'name' => $this->database->destination->network,
                    'attachable' => true,
                ],
            ],
        ];

        if (! is_null($this->database->limits_cpuset)) {
            data_set($docker_compose, "services.{$container_name}.cpuset", $this->database->limits_cpuset);
        }

        $server = data_get($this->database, 'destination.server');
        if ($server instanceof Server && $server->isLogDrainEnabled() && $this->database->isLogDrainEnabled()) {
            $docker_compose['services'][$container_name]['logging'] = generate_fluentd_configuration();
        }

        if (count($this->database->ports_mappings_array) > 0) {
            $docker_compose['services'][$container_name]['ports'] = $this->database->ports_mappings_array;
        }

        $docker_compose['services'][$container_name]['volumes'] ??= [];

        if (count($persistent_storages) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                $persistent_storages
            );
        }

        if (count($persistent_file_volumes) > 0) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                $persistent_file_volumes->map(function ($item) {
                    return "$item->fs_path:$item->mount_path";
                })->toArray()
            );
        }

        if (count($volume_names) > 0) {
            $docker_compose['volumes'] = $volume_names;
        }

        if (! is_null($this->database->keydb_conf) && ! empty($this->database->keydb_conf)) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                [
                    [
                        'type' => 'bind',
                        'source' => $this->configuration_dir.'/keydb.conf',
                        'target' => '/etc/keydb/keydb.conf',
                        'read_only' => true,
                    ],
                ]
            );
        }

        if ($this->database->enable_ssl) {
            $docker_compose['services'][$container_name]['volumes'] = array_merge(
                $docker_compose['services'][$container_name]['volumes'] ?? [],
                [
                    [
                        'type' => 'bind',
                        'source' => '/data/coolify/ssl/coolify-ca.crt',
                        'target' => '/etc/keydb/certs/coolify-ca.crt',
                        'read_only' => true,
                    ],
                ]
            );
        }

        // Add custom docker run options
        $docker_run_options = convertDockerRunToCompose($this->database->custom_docker_run_options);
        $docker_compose = generateCustomDockerRunOptionsForDatabases($docker_run_options, $docker_compose, $container_name, $this->database->destination->network);
        if (! $this->database->isHealthcheckEnabled()) {
            unset($docker_compose['services'][$container_name]['healthcheck']);
        }

        return $docker_compose;
    }

    private function build_commands(string $container_name, string $docker_compose): void
    {
        $docker_compose_base64 = base64_encode($docker_compose);
        $this->commands[] = "echo '{$docker_compose_base64}' | base64 -d | tee $this->configuration_dir/docker-compose.yml > /dev/null";
        $readme = generate_readme_file($this->database->name, now()->toIso8601String());
        $this->commands[] = "echo '{$readme}' > $this->configuration_dir/README.md";
        $this->commands[] = "echo 'Pulling {$this->database->image} image.'";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml pull";
        if ($this->database->enable_ssl) {
            $this->commands[] = "chown -R 999:999 $this->configuration_dir/ssl/server.key $this->configuration_dir/ssl/server.crt";
        }
        if (! is_null($this->database->keydb_conf) && ! empty($this->database->keydb_conf)) {
            $this->commands[] = "chown 999:999 $this->configuration_dir/keydb.conf";
        }
        $this->commands[] = "docker stop -t 10 $container_name 2>/dev/null || true";
        $this->commands[] = "docker rm -f $container_name 2>/dev/null || true";
        $this->commands[] = "docker compose -f $this->configuration_dir/docker-compose.yml up -d";
        $this->commands[] = "echo 'Database started.'";
    }

    /** @return array<int, string> */
    private function generate_environment_variables(): array
    {
        $environment_variables = collect();
        foreach ($this->database->runtime_environment_variables as $env) {
            $environment_variables->push("$env->key=$env->real_value");
        }

        if ($environment_variables->filter(fn ($env) => str($env)->contains('REDIS_PASSWORD'))->isEmpty()) {
            $environment_variables->push("REDIS_PASSWORD={$this->database->keydb_password}");
        }

        add_coolify_default_environment_variables($this->database, $environment_variables, $environment_variables);

        return $environment_variables->all();
    }

    private function add_custom_keydb(): void
    {
        if (is_null($this->database->keydb_conf) || empty($this->database->keydb_conf)) {
            return;
        }
        $filename = 'keydb.conf';
        $content = $this->database->keydb_conf;
        $content_base64 = base64_encode($content);
        $this->commands[] = "echo '{$content_base64}' | base64 -d | tee $this->configuration_dir/{$filename} > /dev/null";
    }

    private function buildStartCommand(): string
    {
        $hasKeydbConf = ! is_null($this->database->keydb_conf) && ! empty($this->database->keydb_conf);
        $keydbConfPath = '/etc/keydb/keydb.conf';

        if ($hasKeydbConf) {
            $confContent = $this->database->keydb_conf;
            $hasRequirePass = str_contains($confContent, 'requirepass');

            if ($hasRequirePass) {
                $command = "keydb-server $keydbConfPath";
            } else {
                $command = "keydb-server $keydbConfPath --requirepass {$this->database->keydb_password}";
            }
        } else {
            $command = "keydb-server --requirepass {$this->database->keydb_password} --appendonly yes";
        }

        if ($this->database->enable_ssl) {
            $sslArgs = [
                '--tls-port 6380',
                '--tls-cert-file /etc/keydb/certs/server.crt',
                '--tls-key-file /etc/keydb/certs/server.key',
                '--tls-ca-cert-file /etc/keydb/certs/coolify-ca.crt',
                '--tls-auth-clients optional',
            ];
            $command .= ' '.implode(' ', $sslArgs);
        }

        return $command;
    }
}
