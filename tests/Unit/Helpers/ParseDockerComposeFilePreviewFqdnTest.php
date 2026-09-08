<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ParseDockerComposeFilePreviewFqdnTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function it_does_not_crash_when_substituting_a_custom_preview_url_template()
    {
        // Regression test: parseDockerComposeFile() is the legacy docker-compose parser
        // (compose_parsing_version < 3) still reachable for applications created before
        // Coolify's newer compose parser was introduced. Its preview-FQDN branch built
        // `$random` as a Cuid2 object and reused the int $pull_request_id straight into
        // str_replace()'s $replace argument. Under strict_types=1 (declared at the top of
        // shared.php), str_replace() rejects both - it does not coerce objects (even
        // Stringable ones) or ints into the array|string it expects - so any preview
        // deployment for an app with a custom preview_url_template containing {{random}}
        // or {{pr_id}} crashed with a TypeError. Confirmed live via a strict_types script
        // before this fix; ApplicationPreview::generatePreviewFqdn() already had the
        // correct (string) casts, this legacy compose-parser copy did not.
        $team = Team::factory()->create();
        $server = Server::factory()->create(['team_id' => $team->id]);
        $server->setRelation('settings', new ServerSetting([
            'is_reachable' => true,
            'is_usable' => true,
            'force_disabled' => false,
        ]));

        $destination = $server->destinations()->first() ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
        $destination->setRelation('server', $server);

        $application = Application::factory()->create([
            'build_pack' => 'dockercompose',
            'docker_compose_raw' => "services:\n  web:\n    image: 'nginx:latest'\n",
            'docker_compose_domains' => json_encode(['web' => ['domain' => 'http://example.com']]),
            'preview_url_template' => '{{random}}-{{pr_id}}.{{domain}}',
        ]);
        // Application::created() unconditionally overwrites compose_parsing_version to the
        // current parser version - update it after creation to force the legacy parser path.
        $application->update(['compose_parsing_version' => '2']);
        $application->setRelation('destination', $destination);

        ApplicationPreview::create([
            'application_id' => $application->id,
            'pull_request_id' => 42,
            'pull_request_html_url' => 'https://github.com/example/repo/pull/42',
        ]);

        expect(fn () => $application->parse(pull_request_id: 42))->not->toThrow(\TypeError::class);
    }
}
