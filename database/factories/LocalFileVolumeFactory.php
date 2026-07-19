<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Application;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocalFileVolumeFactory extends Factory
{
    public function definition(): array
    {
        $resource = Application::factory()->create();

        return [
            'fs_path' => '/data/'.fake()->unique()->word(),
            'mount_path' => '/'.fake()->word(),
            'resource_type' => $resource->getMorphClass(),
            'resource_id' => $resource->id,
            'is_directory' => false,
        ];
    }
}
