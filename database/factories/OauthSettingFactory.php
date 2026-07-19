<?php

declare(strict_types=1);

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class OauthSettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'provider' => fake()->randomElement(['azure', 'authentik', 'clerk', 'google', 'github']),
            'enabled' => false,
        ];
    }
}
