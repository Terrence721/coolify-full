<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Exceptions\RateLimitException;
use App\Services\HetznerService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class HetznerServiceTest extends TestCase
{
    #[Test]
    public function successful_request_returns_json()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations?page=1&per_page=50' => Http::response([
                'locations' => [['id' => 1]],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->getLocations();

        $this->assertSame([['id' => 1]], $result);
    }

    #[Test]
    public function rate_limit_exception_is_thrown_on_429()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations?page=1&per_page=50' => Http::response([], 429, [
                'Retry-After' => 5,
            ]),
        ]);

        $service = new HetznerService('token123');

        $this->expectException(RateLimitException::class);

        try {
            $service->getLocations();
        } catch (RateLimitException $e) {
            $this->assertSame(5, $e->retryAfter);
            throw $e;
        }
    }

    #[Test]
    public function rate_limit_reset_header_is_used_when_retry_after_missing()
    {
        Sleep::fake();

        $resetTime = time() + 10;

        Http::fake([
            'https://api.hetzner.cloud/v1/locations?page=1&per_page=50' => Http::response([], 429, [
                'RateLimit-Reset' => $resetTime,
            ]),
        ]);

        $service = new HetznerService('token123');

        $this->expectException(RateLimitException::class);

        try {
            $service->getLocations();
        } catch (RateLimitException $e) {
            $this->assertSame(10, $e->retryAfter);
            throw $e;
        }
    }

    #[Test]
    public function paginated_requests_are_combined()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/locations?page=1&per_page=50' => Http::response([
                'locations' => [['id' => 1]],
                'meta' => ['pagination' => ['next_page' => 2]],
            ], 200),

            'https://api.hetzner.cloud/v1/locations?page=2&per_page=50' => Http::response([
                'locations' => [['id' => 2]],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->getLocations();

        $this->assertSame([['id' => 1], ['id' => 2]], $result);
    }

    #[Test]
    public function deprecated_server_types_are_filtered_out()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/server_types?page=1&per_page=50' => Http::response([
                'server_types' => [
                    ['id' => 1, 'deprecated' => true],
                    ['id' => 2, 'deprecated' => false],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->getServerTypes();

        $this->assertSame([['id' => 2, 'deprecated' => false]], $result);
    }

    #[Test]
    public function upload_ssh_key_returns_key()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/ssh_keys' => Http::response([
                'ssh_key' => ['id' => 10, 'name' => 'mykey'],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->uploadSshKey('mykey', 'ssh-rsa AAA...');

        $this->assertSame(['id' => 10, 'name' => 'mykey'], $result);
    }

    #[Test]
    public function create_server_returns_server()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers' => Http::response([
                'server' => ['id' => 99],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->createServer(['name' => 'test']);

        $this->assertSame(['id' => 99], $result);
    }

    #[Test]
    public function get_server_returns_server()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/5' => Http::response([
                'server' => ['id' => 5],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->getServer(5);

        $this->assertSame(['id' => 5], $result);
    }

    #[Test]
    public function power_on_server_returns_action()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/5/actions/poweron' => Http::response([
                'action' => ['id' => 123],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->powerOnServer(5);

        $this->assertSame(['id' => 123], $result);
    }

    #[Test]
    public function delete_server_succeeds()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers/5' => Http::response([], 200),
        ]);

        $service = new HetznerService('token123');
        $service->deleteServer(5);

        Http::assertSent(fn ($request) => $request->url() === 'https://api.hetzner.cloud/v1/servers/5'
            && $request->method() === 'DELETE');
    }

    #[Test]
    public function find_server_by_ipv4()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers?page=1&per_page=50' => Http::response([
                'servers' => [
                    ['id' => 1, 'public_net' => ['ipv4' => ['ip' => '1.2.3.4']]],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->findServerByIp('1.2.3.4');

        $this->assertSame(1, $result['id']);
    }

    #[Test]
    public function find_server_by_ipv6_prefix()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers?page=1&per_page=50' => Http::response([
                'servers' => [
                    ['id' => 2, 'public_net' => ['ipv6' => ['ip' => '2001:db8::/64']]],
                ],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->findServerByIp('2001:db8::1');

        $this->assertSame(2, $result['id']);
    }

    #[Test]
    public function find_server_returns_null_when_not_found()
    {
        Http::fake([
            'https://api.hetzner.cloud/v1/servers?page=1&per_page=50' => Http::response([
                'servers' => [],
                'meta' => ['pagination' => ['next_page' => null]],
            ], 200),
        ]);

        $service = new HetznerService('token123');
        $result = $service->findServerByIp('8.8.8.8');

        $this->assertNull($result);
    }
}
