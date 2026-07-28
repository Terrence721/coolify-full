<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\PrivateKey;
use App\Models\Server;
use Illuminate\Database\Seeder;

/**
 * Registers the opt-in, genuinely isolated Docker-in-Docker "remote server"
 * (docker-compose.smoketest.yml, docker/smoketest-host/Dockerfile) with Coolify — a distinct
 * PrivateKey + Server + ServerSetting trio, mirroring PrivateKeySeeder/ServerSeeder/
 * ServerSettingSeeder's pattern for `coolify-testing-host`, but kept out of
 * DatabaseSeeder::run()'s default chain on purpose: this server only exists when
 * docker-compose.smoketest.yml has actually been started (see docs/command.md).
 *
 * Deliberately does NOT pre-set is_reachable/is_usable to true the way ServerSettingSeeder does
 * for the testing-host row — this target is meant to be genuinely validated through Coolify's
 * real "Validate Server" flow, not shortcut, since the whole point is proving it as a real
 * managed server.
 *
 * Run manually: docker exec coolify php artisan db:seed --class=SmoketestHostSeeder --no-interaction
 */
class SmoketestHostSeeder extends Seeder
{
    public function run(): void
    {
        $privateKey = PrivateKey::create([
            'uuid' => 'smoketest-host-key',
            'team_id' => 0,
            'name' => 'Smoketest Host Key',
            'description' => 'Key for the opt-in, isolated Docker-in-Docker smoke-test host (docker-compose.smoketest.yml) — not coolify-testing-host, which shares the real host'."'".'s docker.sock.',
            'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBVr9RVk5f7B/lOZCt5SH1L7wegSTrO/RgXJiY1i6TcxwAAAKB78ib8e/Im
/AAAAAtzc2gtZWQyNTUxOQAAACBVr9RVk5f7B/lOZCt5SH1L7wegSTrO/RgXJiY1i6Tcxw
AAAEBX4bPEUzLSvsTg/j0WkvmUWOs0IhHFTDNGtyDMsXBrXFWv1FWTl/sH+U5kK3lIfUvv
B6BJOs79GBcmJjWLpNzHAAAAFmNvb2xpZnlAc21va2V0ZXN0LWhvc3QBAgMEBQYH
-----END OPENSSH PRIVATE KEY-----
',
        ]);

        // Materialize just this one key to disk (Coolify's SSH client reads from here at
        // connect-time) without touching every other PrivateKey's files the way
        // PopulateSshKeysDirectorySeeder's directory wipe-and-rebuild does.
        $privateKey->storeInFileSystem();

        $server = Server::create([
            'uuid' => 'smoketest-host',
            'name' => 'smoketest-host',
            'description' => 'Opt-in, genuinely isolated Docker-in-Docker smoke-test target — its own dockerd, not the real host'."'".'s socket. See docs/command.md.',
            'ip' => 'coolify-smoketest-host',
            'team_id' => 0,
            'private_key_id' => $privateKey->id,
            'proxy' => [
                'type' => ProxyTypes::NONE->value,
                'status' => ProxyStatus::EXITED->value,
            ],
        ]);

        $server->settings->wildcard_domain = null;
        $server->settings->is_build_server = false;
        $server->settings->save();
    }
}
