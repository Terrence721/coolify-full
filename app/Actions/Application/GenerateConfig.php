<?php

declare(strict_types=1);

namespace App\Actions\Application;

use App\Models\Application;
use Lorisleiva\Actions\Concerns\AsAction;

class GenerateConfig
{
    use AsAction;

    /**
     * @return string|array<string, mixed>
     */
    public function handle(Application $application, bool $is_json = false): string|array
    {
        return $application->generateConfig(is_json: $is_json);
    }
}
