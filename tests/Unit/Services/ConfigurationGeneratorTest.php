<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Services\ConfigurationGenerator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class ConfigurationGeneratorTest extends TestCase
{
    use RefreshDatabase;

    private Application $application;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake project
        $project = Project::factory()->create([
            'uuid' => 'project-uuid',
        ]);

        // Fake environment
        $environment = Environment::factory()->create([
            'project_id' => $project->id,
            'uuid' => 'environment-uuid',
        ]);

        // Fake application
        $this->application = Application::factory()->create([
            'environment_id' => $environment->id,
            'name' => 'MyApp',
            'uuid' => 'app-uuid',
            'description' => 'Test app',
            'destination_type' => 'docker',
            'destination_id' => 10,
            'source_type' => 'git',
            'source_id' => 20,
            'private_key_id' => 30,
            'post_deployment_command' => 'echo post',
            'post_deployment_command_container' => 'container-post',
            'pre_deployment_command' => 'echo pre',
            'pre_deployment_command_container' => 'container-pre',
            'build_pack' => 'node',
            'static_image' => 'nginx:latest',
            'base_directory' => '/var/www',
            'publish_directory' => '/var/www/public',
            'dockerfile' => 'FROM node',
            'dockerfile_location' => '/docker/Dockerfile',
            'dockerfile_target_build' => 'builder',
            'custom_docker_run_options' => '--privileged',
            'compose_parsing_version' => 'v2',
            'docker_compose' => '{}',
            'docker_compose_location' => '/compose/docker-compose.yml',
            'docker_compose_raw' => '{"raw":true}',
            'docker_compose_domains' => '{"web":{"domain":"example.com"}}',
            'docker_compose_custom_start_command' => 'npm start',
            'docker_compose_custom_build_command' => 'npm build',
            'install_command' => 'npm install',
            'build_command' => 'npm run build',
            'start_command' => 'npm start',
            'watch_paths' => "src\nconfig",
            'git_repository' => 'https://github.com/example/repo',
            'git_branch' => 'main',
            'git_commit_sha' => 'abc123',
            'repository_project_id' => 99,
            'docker_registry_image_name' => 'myapp',
            'docker_registry_image_tag' => 'latest',
            'fqdn' => 'myapp.example.com',
            'ports_exposes' => '80',
            'ports_mappings' => '80:8080',
            'custom_nginx_configuration' => 'server {}',
            'preview_url_template' => 'https://preview.example.com/{branch}',
            'health_check_path' => '/health',
            'health_check_port' => 8080,
            'health_check_host' => 'localhost',
            'health_check_method' => 'GET',
            'health_check_return_code' => 200,
            'health_check_scheme' => 'http',
            'health_check_response_text' => 'OK',
            'health_check_interval' => 10,
            'health_check_timeout' => 5,
            'health_check_retries' => 3,
            'health_check_start_period' => 2,
            'health_check_enabled' => true,
            'manual_webhook_secret_github' => 'gh-secret',
            'manual_webhook_secret_gitlab' => 'gl-secret',
            'manual_webhook_secret_bitbucket' => 'bb-secret',
            'manual_webhook_secret_gitea' => 'gitea-secret',
            'swarm_replicas' => 2,
            'swarm_placement_constraints' => base64_encode('node.role==worker'),
        ]);

        // Fake settings (unpersisted; ApplicationSetting has no factory and these aren't real columns)
        $settings = new ApplicationSetting;
        $settings->setRawAttributes([
            'id' => 1,
            'application_id' => $this->application->id,
            'created_at' => now(),
            'updated_at' => now(),
            'foo' => 'bar',
            'baz' => 'qux',
        ]);
        $this->application->setRelation('settings', $settings);

        // Fake environment variables
        $this->application->setRelation('environment_variables', collect([
            new EnvironmentVariable([
                'key' => 'APP_KEY',
                'value' => 'secret',
                'is_preview' => false,
                'is_multiline' => false,
            ]),
        ]));

        // Fake preview environment variables
        $this->application->setRelation('environment_variables_preview', collect([
            new EnvironmentVariable([
                'key' => 'PREVIEW_KEY',
                'value' => 'preview-secret',
                'is_preview' => true,
                'is_multiline' => false,
            ]),
        ]));
    }

    #[Test]
    public function it_generates_configuration_array()
    {
        $generator = new ConfigurationGenerator($this->application);
        $config = $generator->toArray();

        $this->assertSame('MyApp', $config['name']);
        $this->assertSame('app-uuid', $config['uuid']);
        $this->assertSame('project-uuid', $config['coolify_details']['project_uuid']);
        $this->assertSame('environment-uuid', $config['coolify_details']['environment_uuid']);
        $this->assertSame('node', $config['build']['type']);
        $this->assertSame('myapp', $config['docker_registry_image']['image']);
        $this->assertSame('latest', $config['docker_registry_image']['tag']);
        $this->assertSame('myapp.example.com', $config['domains']['fqdn']);
        $this->assertSame('/health', $config['health_check']['health_check_path']);
        $this->assertSame('gh-secret', $config['webhooks_secrets']['manual_webhook_secret_github']);
        $this->assertSame(2, $config['swarm']['swarm_replicas']);
    }

    #[Test]
    public function it_generates_environment_variables()
    {
        $generator = new ConfigurationGenerator($this->application);
        $config = $generator->toArray();

        $prod = $config['environment_variables']['production'][0];
        $preview = $config['environment_variables']['preview'][0];

        $this->assertSame('APP_KEY', $prod['key']);
        $this->assertSame('secret', $prod['value']);

        $this->assertSame('PREVIEW_KEY', $preview['key']);
        $this->assertSame('preview-secret', $preview['value']);
    }

    #[Test]
    public function it_generates_settings_without_removed_keys()
    {
        $generator = new ConfigurationGenerator($this->application);
        $config = $generator->toArray();

        $settings = $config['settings'];

        $this->assertArrayHasKey('foo', $settings);
        $this->assertArrayHasKey('baz', $settings);

        $this->assertArrayNotHasKey('id', $settings);
        $this->assertArrayNotHasKey('application_id', $settings);
        $this->assertArrayNotHasKey('created_at', $settings);
        $this->assertArrayNotHasKey('updated_at', $settings);
    }

    #[Test]
    public function it_outputs_json_correctly()
    {
        $generator = new ConfigurationGenerator($this->application);
        $json = $generator->toJson();

        $decoded = json_decode($json, true);

        $this->assertSame('MyApp', $decoded['name']);
        $this->assertSame('node', $decoded['build']['type']);
    }

    #[Test]
    public function it_outputs_yaml_correctly()
    {
        $generator = new ConfigurationGenerator($this->application);
        $yaml = $generator->toYaml();

        $parsed = Yaml::parse($yaml);

        $this->assertSame('MyApp', $parsed['name']);
        $this->assertSame('node', $parsed['build']['type']);
    }

    #[Test]
    public function it_saves_json_to_disk()
    {
        $generator = new ConfigurationGenerator($this->application);

        $path = storage_path('test-config.json');
        @unlink($path);

        $generator->saveJson($path);

        $this->assertFileExists($path);

        $decoded = json_decode(file_get_contents($path), true);
        $this->assertSame('MyApp', $decoded['name']);
    }

    #[Test]
    public function it_saves_yaml_to_disk()
    {
        $generator = new ConfigurationGenerator($this->application);

        $path = storage_path('test-config.yaml');
        @unlink($path);

        $generator->saveYaml($path);

        $this->assertFileExists($path);

        $parsed = Yaml::parse(file_get_contents($path));
        $this->assertSame('MyApp', $parsed['name']);
    }
}
