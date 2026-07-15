<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StandaloneDocker;
use Illuminate\Database\Eloquent\Factories\Factory;

class StandaloneKeydbFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->word(),
            'keydb_password' => 'secret',
            'destination_type' => StandaloneDocker::class,
            'destination_id' => 1,
            'environment_id' => 1,
        ];
    }
}
