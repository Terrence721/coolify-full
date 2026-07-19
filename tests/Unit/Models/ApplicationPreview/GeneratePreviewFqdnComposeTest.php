<?php

declare(strict_types=1);

namespace Tests\Unit\Models\ApplicationPreview;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneratePreviewFqdnComposeTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_crash_when_the_applications_docker_compose_domains_is_null()
    {
        // Regression test: docker_compose_domains is a nullable column with no default
        // (migration: $table->text('docker_compose_domains')->nullable()), so any
        // docker-compose application that hasn't had preview domains generated yet has
        // it as null. The old code passed it straight into json_decode() with no cast
        // or null guard, which fatally TypeErrors under strict_types=1
        // ("json_decode(): Argument #1 ($json) must be of type string, null given") -
        // meaning the very first preview-fqdn generation for any fresh docker-compose
        // app would crash before ever reaching parse() or generating a single domain.
        $server = Server::factory()->make();
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ]));

        $destination = StandaloneDocker::factory()->make();
        $destination->setRelation('server', $server);

        $application = Application::factory()->create([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => null,
            'docker_compose_domains' => null,
        ]);
        $application->setRelation('destination', $destination);

        $preview = ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => 42,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
            'docker_compose_domains' => null,
        ]);
        $preview->setRelation('application', $application);

        expect(fn () => $preview->generate_preview_fqdn_compose())->not->toThrow(\TypeError::class);
    }
}
