<?php

namespace Database\Factories;

use App\Models\Provider;
use App\Models\ProviderUser;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<ProviderUser>
 */
class ProviderUserFactory extends Factory
{
    protected $model = ProviderUser::class;

    public function definition(): array
    {
        return [
            'provider_id' => Provider::factory(),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
        ];
    }
}
