<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\GenerateConfig;
use App\Models\Application;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateConfigTest extends TestCase
{
    #[Test]
    public function it_returns_array_config_when_is_json_is_false()
    {
        $application = $this->createMock(Application::class);

        $application->expects($this->once())
            ->method('generateConfig')
            ->with($this->equalTo(false))
            ->willReturn(['name' => 'MyApp']);

        $action = new GenerateConfig;
        $result = $action->handle($application, false);

        $this->assertSame(['name' => 'MyApp'], $result);
    }

    #[Test]
    public function it_returns_json_string_when_is_json_is_true()
    {
        $application = $this->createMock(Application::class);

        $application->expects($this->once())
            ->method('generateConfig')
            ->with($this->equalTo(true))
            ->willReturn('{"name":"MyApp"}');

        $action = new GenerateConfig;
        $result = $action->handle($application, true);

        $this->assertSame('{"name":"MyApp"}', $result);
    }

    #[Test]
    public function it_passes_default_is_json_false()
    {
        $application = $this->createMock(Application::class);

        $application->expects($this->once())
            ->method('generateConfig')
            ->with($this->equalTo(false))
            ->willReturn(['default' => true]);

        $action = new GenerateConfig;
        $result = $action->handle($application);

        $this->assertSame(['default' => true], $result);
    }
}
