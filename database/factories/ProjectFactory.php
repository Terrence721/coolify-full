<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProjectFactory extends Factory
{
    public function definition(): array
    {
        return [
            // Faker's company() occasionally includes an apostrophe (e.g. "O'Conner Group"), which
            // App\Support\ValidationPatterns::NAME_PATTERN rejects - strip it so tests that
            // round-trip this name through name-validated endpoints don't flake.
            'name' => str_replace("'", '', fake()->unique()->company()),
            'team_id' => 1,
        ];
    }
}
