<?php

declare(strict_types=1);

namespace Tests\Unit\Actions\Application;

use App\Actions\Application\LoadComposeFile;
use App\Models\Application;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class LoadComposeFileTest extends TestCase
{
    #[Test]
    public function it_calls_load_compose_file_on_application()
    {
        $application = $this->createMock(Application::class);

        // Expect the method to be called exactly once
        $application->expects($this->once())
            ->method('loadComposeFile');

        $action = new LoadComposeFile;
        $action->handle($application);
    }

    #[Test]
    public function it_passes_the_correct_application_instance()
    {
        $application = $this->createMock(Application::class);

        // Ensure the same instance is passed
        $application->expects($this->once())
            ->method('loadComposeFile')
            ->with(); // no arguments expected

        $action = new LoadComposeFile;
        $action->handle($application);

        $this->assertTrue(true); // If no exception, the test passes
    }
}
