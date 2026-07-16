<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ApplicationFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Faker's name() occasionally includes an apostrophe (e.g. "O'Connell"), which
            // App\Support\ValidationPatterns::NAME_PATTERN rejects - strip it so tests that
            // round-trip this name through name-validated endpoints don't flake.
            'name' => str_replace("'", '', fake()->unique()->name()),
            'destination_id' => 1,
            'git_repository' => fake()->url(),
            'git_branch' => fake()->word(),
            'build_pack' => 'nixpacks',
            'ports_exposes' => '3000',
            'environment_id' => 1,
            'destination_id' => 1,
        ];
    }
}
