<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Models\Server;
use App\Services\ProxyDashboardCacheService;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ProxyDashboardCacheServiceTest extends TestCase
{
    #[Test]
    public function it_generates_correct_cache_key()
    {
        $server = Server::factory()->make(['id' => 10]);

        $key = ProxyDashboardCacheService::getCacheKey($server);

        $this->assertSame('server:10:traefik:dashboard_available', $key);
    }

    #[Test]
    public function it_detects_dashboard_available_from_configuration()
    {
        Cache::shouldReceive('forever')
            ->once()
            ->with('server:5:traefik:dashboard_available', true);

        $server = Server::factory()->make(['id' => 5]);

        $config = '--api.dashboard=true --api.insecure=true';

        ProxyDashboardCacheService::isTraefikDashboardAvailableFromConfiguration($server, $config);
    }

    #[Test]
    public function it_detects_dashboard_not_available_from_configuration()
    {
        Cache::shouldReceive('forever')
            ->once()
            ->with('server:5:traefik:dashboard_available', false);

        $server = Server::factory()->make(['id' => 5]);

        $config = '--api.dashboard=false --api.insecure=true';

        ProxyDashboardCacheService::isTraefikDashboardAvailableFromConfiguration($server, $config);
    }

    #[Test]
    public function it_reads_dashboard_availability_from_cache()
    {
        Cache::shouldReceive('get')
            ->once()
            ->with('server:7:traefik:dashboard_available')
            ->andReturn(true);

        $server = Server::factory()->make(['id' => 7]);

        $result = ProxyDashboardCacheService::isTraefikDashboardAvailableFromCache($server);

        $this->assertTrue($result);
    }

    #[Test]
    public function it_returns_false_when_cache_missing()
    {
        Cache::shouldReceive('get')
            ->once()
            ->with('server:7:traefik:dashboard_available')
            ->andReturn(null);

        $server = Server::factory()->make(['id' => 7]);

        $result = ProxyDashboardCacheService::isTraefikDashboardAvailableFromCache($server);

        $this->assertFalse($result);
    }

    #[Test]
    public function it_clears_cache_for_single_server()
    {
        Cache::shouldReceive('forget')
            ->once()
            ->with('server:3:traefik:dashboard_available');

        $server = Server::factory()->make(['id' => 3]);

        ProxyDashboardCacheService::clearCache($server);
    }

    #[Test]
    public function it_clears_cache_for_multiple_servers()
    {
        Cache::shouldReceive('forget')->once()->with('server:1:traefik:dashboard_available');
        Cache::shouldReceive('forget')->once()->with('server:2:traefik:dashboard_available');
        Cache::shouldReceive('forget')->once()->with('server:3:traefik:dashboard_available');

        ProxyDashboardCacheService::clearCacheForServers([1, 2, 3]);
    }
}
