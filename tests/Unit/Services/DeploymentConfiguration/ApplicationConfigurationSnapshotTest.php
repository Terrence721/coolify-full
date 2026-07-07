<?php

declare(strict_types=1);

namespace Tests\Unit\Services\DeploymentConfiguration;

use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Services\DeploymentConfiguration\ApplicationConfigurationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ApplicationConfigurationSnapshotTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_generates_a_snapshot_array_with_expected_sections()
    {
        $app = Application::factory()->create([
            'git_repository' => 'https://github.com/example/repo',
            'git_branch' => 'main',
            'git_commit_sha' => 'abc123',
        ]);

        $snapshot = new ApplicationConfigurationSnapshot($app);
        $array = $snapshot->toArray();

        $this->assertArrayHasKey('schema_version', $array);
        $this->assertSame(1, $array['schema_version']);

        $this->assertArrayHasKey('resource_type', $array);
        $this->assertSame(Application::class, $array['resource_type']);

        $this->assertArrayHasKey('resource_id', $array);
        $this->assertSame($app->id, $array['resource_id']);

        $this->assertArrayHasKey('sections', $array);
        $this->assertArrayHasKey('source', $array['sections']);
        $this->assertArrayHasKey('build', $array['sections']);
        $this->assertArrayHasKey('runtime', $array['sections']);
        $this->assertArrayHasKey('domains', $array['sections']);
        $this->assertArrayHasKey('environment', $array['sections']);
    }

    #[Test]
    public function hash_is_stable_for_identical_snapshots()
    {
        $app = Application::factory()->create([
            'git_repository' => 'repo',
            'git_branch' => 'main',
        ]);

        $snapshot1 = new ApplicationConfigurationSnapshot($app);
        $snapshot2 = new ApplicationConfigurationSnapshot($app);

        $this->assertSame($snapshot1->hash(), $snapshot2->hash());
    }

    #[Test]
    public function comparable_snapshot_sorts_sections_and_items()
    {
        $snapshot = [
            'schema_version' => 1,
            'sections' => [
                'z_section' => [
                    'items' => [
                        ['key' => 'b', 'compare_value' => 2],
                        ['key' => 'a', 'compare_value' => 1],
                    ],
                ],
                'a_section' => [
                    'items' => [
                        ['key' => 'd', 'compare_value' => 4],
                        ['key' => 'c', 'compare_value' => 3],
                    ],
                ],
            ],
        ];

        $result = ApplicationConfigurationSnapshot::comparableSnapshot($snapshot);

        $this->assertSame(['a_section', 'z_section'], array_keys($result['sections']));
        $this->assertSame(['c', 'd'], array_keys($result['sections']['a_section']));
        $this->assertSame(['a', 'b'], array_keys($result['sections']['z_section']));
    }

    #[Test]
    public function normalizes_values_through_the_public_snapshot()
    {
        $app = Application::factory()->create([
            'git_branch' => 'main',
            'redirect' => '',
            'health_check_port' => 3000,
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => 'example.com'],
                'api' => ['domain' => 'api.example.com'],
            ]),
        ]);
        $app->settings->update(['is_force_https_enabled' => true]);

        $array = (new ApplicationConfigurationSnapshot($app))->toArray();

        // Strings pass through unchanged.
        $this->assertSame('main', $this->findItem($array, 'source', 'git_branch')['compare_value']);

        // Empty strings normalize to null, and display as "-".
        $redirectItem = $this->findItem($array, 'domains', 'redirect');
        $this->assertNull($redirectItem['compare_value']);
        $this->assertSame('-', $redirectItem['display_value']);

        // Numbers pass through unchanged.
        $this->assertEquals(3000, $this->findItem($array, 'runtime', 'health_check_port')['compare_value']);

        // Booleans pass through unchanged and render as Enabled/Disabled.
        $httpsItem = $this->findItem($array, 'domains', 'is_force_https_enabled');
        $this->assertTrue($httpsItem['compare_value']);
        $this->assertSame('Enabled', $httpsItem['display_value']);

        // Arrays are recursively sorted by key.
        $domainsItem = $this->findItem($array, 'domains', 'docker_compose_domains');
        $this->assertSame(['api', 'web'], array_keys($domainsItem['compare_value']));
    }

    #[Test]
    public function sensitive_items_hash_their_compare_value_and_hide_display_full()
    {
        $app = Application::factory()->create([
            'is_http_basic_auth_enabled' => true,
            'http_basic_auth_password' => 'myvalue',
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'domains', 'http_basic_auth_password');

        $this->assertTrue($item['sensitive']);
        $this->assertNotSame('myvalue', $item['compare_value']);
        $this->assertNull($item['display_full']);
    }

    #[Test]
    public function non_sensitive_items_keep_their_raw_compare_value()
    {
        $app = Application::factory()->create([
            'build_command' => 'npm run build',
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'build', 'build_command');

        $this->assertSame('npm run build', $item['compare_value']);
        $this->assertFalse($item['sensitive']);
    }

    #[Test]
    public function multiline_values_expose_an_expandable_display_full()
    {
        $dockerfile = "FROM node:20\nRUN npm install";

        $app = Application::factory()->create([
            'dockerfile' => $dockerfile,
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'build', 'dockerfile');

        $this->assertFalse($item['sensitive']);
        $this->assertSame($dockerfile, $item['display_full']);
    }

    #[Test]
    public function locked_environment_variables_are_hidden_in_the_snapshot()
    {
        $app = Application::factory()->create();

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $app->id,
            'key' => 'SECRET_KEY',
            'value' => 'supersecret',
            'is_shown_once' => true,
            'is_buildtime' => true,
            'is_runtime' => false,
            'is_multiline' => false,
            'is_literal' => false,
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'environment', 'SECRET_KEY');

        $this->assertTrue($item['sensitive']);
        $this->assertStringContainsString('Hidden', $item['display_value']);
        $this->assertNull($item['display_full']);
    }

    #[Test]
    public function unlocked_environment_variables_expose_their_value_in_the_snapshot()
    {
        $app = Application::factory()->create();

        EnvironmentVariable::create([
            'resourceable_type' => Application::class,
            'resourceable_id' => $app->id,
            'key' => 'API_KEY',
            'value' => 'abc123',
            'is_shown_once' => false,
            'is_buildtime' => true,
            'is_runtime' => true,
            'is_multiline' => false,
            'is_literal' => false,
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'environment', 'API_KEY');

        $this->assertFalse($item['sensitive']);
        $this->assertNotNull($item['display_full']);
        $this->assertStringContainsString('abc123', $item['display_full']);
        $this->assertStringContainsString('Available at build: enabled', $item['display_full']);
    }

    #[Test]
    public function docker_compose_domains_item_is_blank_when_not_set()
    {
        $app = Application::factory()->create([
            'docker_compose_domains' => null,
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'domains', 'docker_compose_domains');

        $this->assertNull($item['compare_value']);
        $this->assertSame('-', $item['display_value']);
        $this->assertNull($item['display_full']);
    }

    #[Test]
    public function docker_compose_domains_item_reflects_decoded_json()
    {
        $app = Application::factory()->create([
            'docker_compose_domains' => json_encode([
                'web' => ['domain' => 'example.com'],
            ]),
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'domains', 'docker_compose_domains');

        $this->assertSame(
            ['web' => ['domain' => 'example.com']],
            $item['compare_value']
        );
    }

    #[Test]
    public function docker_compose_domains_item_formats_display_full_per_service()
    {
        $app = Application::factory()->create([
            'docker_compose_domains' => json_encode([
                'api' => ['domain' => 'api.example.com'],
                'web' => ['domain' => 'example.com'],
            ]),
        ]);

        $item = $this->findItem((new ApplicationConfigurationSnapshot($app))->toArray(), 'domains', 'docker_compose_domains');

        $this->assertStringContainsString('api: api.example.com', $item['display_full']);
        $this->assertStringContainsString('web: example.com', $item['display_full']);
    }

    private function findItem(array $snapshot, string $section, string $key): ?array
    {
        return collect($snapshot['sections'][$section]['items'] ?? [])
            ->firstWhere('key', $key);
    }
}
