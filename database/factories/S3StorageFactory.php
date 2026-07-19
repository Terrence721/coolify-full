<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class S3StorageFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'key' => fake()->uuid(),
            'secret' => fake()->uuid(),
            'bucket' => fake()->word(),
            'team_id' => Team::factory(),
        ];
    }
}
