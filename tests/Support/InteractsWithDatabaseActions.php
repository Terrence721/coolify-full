<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Server;
use App\Models\SslCertificate;

/**
 * Shared fixture builders for the App\Actions\Database Start* action tests
 * (StartDragonfly, StartKeydb, StartMariadb, ...). These actions all follow the same
 * shape: a `destination` relation exposing `network`/`server`, and an SSL branch that
 * looks up a CA certificate on the server before generating (or reusing) the database's
 * own certificate.
 */
trait InteractsWithDatabaseActions
{
    /** Invoke a protected/private method on $object via Reflection. */
    private function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        return (new \ReflectionMethod($object, $method))->invoke($object, ...$args);
    }

    /** Auto-incrementing suffix keeps uuid/ip unique across every test using this trait in the run. */
    private function createTestServer(array $overrides = []): Server
    {
        static $count = 0;
        $count++;

        return Server::create(array_merge([
            'name' => "srv-test-{$count}",
            'uuid' => "srv-test-{$count}-uuid",
            'ip' => '127.0.1.'.($count % 254 + 1),
            'team_id' => 1,
            'private_key_id' => 1,
        ], $overrides));
    }

    /**
     * Pre-seeds a CA certificate so the SSL branch finds an existing one instead of
     * calling Server::generateCaCertificate() -> SslHelper::generateSslCertificate(),
     * which relies on openssl_pkey_new() — unavailable in this environment.
     */
    private function seedCaCertificate(Server $server): SslCertificate
    {
        return SslCertificate::create([
            'ssl_certificate' => 'ca-cert',
            'ssl_private_key' => 'ca-key',
            'server_id' => $server->id,
            'common_name' => 'Coolify CA Certificate',
            'valid_until' => now()->addYears(10),
            'is_ca_certificate' => true,
        ]);
    }

    /** Pre-seeds the database's own certificate to avoid SslHelper::generateSslCertificate(). */
    private function seedResourceCertificate(Server $server, string $resourceType, int $resourceId, string $commonName): SslCertificate
    {
        return SslCertificate::create([
            'ssl_certificate' => 'db-cert',
            'ssl_private_key' => 'db-key',
            'server_id' => $server->id,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'common_name' => $commonName,
            'valid_until' => now()->addYear(),
            'is_ca_certificate' => false,
        ]);
    }

    /** destination.server as an object — sslCertificates()/generateCaCertificate() are called through it. */
    private function destinationWithServer(Server $server, string $network = 'net-1'): object
    {
        return new class($server, $network)
        {
            public function __construct(public Server $server, public string $network) {}
        };
    }

    /** destination without a server yet — used for the non-SSL fixture default. */
    private function destinationWithoutServer(string $network = 'net-1'): object
    {
        return new class($network)
        {
            public ?Server $server = null;

            public function __construct(public string $network) {}
        };
    }
}
